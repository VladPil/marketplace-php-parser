<?php

declare(strict_types=1);

namespace App\Module\Parser\Worker;

use App\Module\Parser\Identity\Identity;
use App\Module\Parser\Identity\IdentityBlockedException;
use App\Module\Parser\Identity\IdentityPool;
use App\Module\Parser\Identity\IdentityPoolConfig;
use App\Module\Parser\Marketplace\MarketplaceRegistry;
use App\Shared\Contract\CategoryStorageInterface;
use App\Shared\Contract\ProductStorageInterface;
use App\Shared\Contract\ProgressTrackerInterface;
use App\Shared\Contract\TaskHandlerInterface;
use App\Shared\Contract\TaskStorageInterface;
use App\Shared\DTO\CategoryData;
use App\Shared\DTO\ProductData;
use App\Shared\DTO\TaskResult;
use App\Shared\Tracing\TraceContext;
use App\Module\Parser\Marketplace\Ozon\OzonHtmlParser;
use App\Shared\Logging\ParseLogger;

/**
 * Обработчик задач парсинга одного товара.
 *
 * Выполняет 2 запроса:
 * 1. HTML page 1 (SSR) — Schema.org, галерея, категории
 * 2. API page 2 (JSON) — характеристики, описание, отзывы
 *
 * При наличии Identity Pool:
 *   claim identity → выполнить парсинг → release identity
 *   При 403 (IdentityBlockedException) → quarantine → claim новую → retry
 */
final class ProductTaskHandler implements TaskHandlerInterface
{
    public function __construct(
        private readonly MarketplaceRegistry $marketplaceRegistry,
        private readonly ProductStorageInterface $productStorage,
        private readonly CategoryStorageInterface $categoryStorage,
        private readonly TaskStorageInterface $taskStorage,
        private readonly ProgressTrackerInterface $progressTracker,
        private readonly ReviewCollector $reviewCollector,
        private readonly OzonHtmlParser $htmlParser,
        private readonly ParseLogger $logger,
        private readonly IdentityPool $identityPool,
        private readonly IdentityPoolConfig $identityPoolConfig,
    ) {
    }

    /** {@inheritdoc} */
    public function supports(string $taskType): bool
    {
        return $taskType === 'product';
    }

    /**
     * Обрабатывает задачу парсинга одного товара.
     *
     * Оборачивает логику парсинга в цикл claim/release/quarantine identity.
     * При IdentityBlockedException (403) — карантин identity, захват новой, повтор.
     *
     * @param string $taskId Идентификатор задачи
     * @param array $params Параметры (external_id, slug, marketplace)
     */
    public function handle(string $taskId, array $params): TaskResult
    {
        return $this->executeWithIdentity($taskId, $params);
    }

    /**
     * Выполняет парсинг с привязкой к identity из пула.
     *
     * @param int $attempt Текущая попытка (0-based)
     */
    private function executeWithIdentity(string $taskId, array $params, int $attempt = 0): TaskResult
    {
        $identity = $this->identityPool->claim($taskId);

        if ($identity !== null) {
            TraceContext::setIdentity($identity);
            $this->logger->info(sprintf(
                'Identity %s привязана к задаче (прокси: %s, попытка %d)',
                substr($identity->id, 0, 8),
                $identity->maskedProxy(),
                $attempt + 1,
            ));
        } else {
            $this->logger->info(sprintf(
                'Нет готовых identity в пуле, работаем в legacy-режиме (попытка %d)',
                $attempt + 1,
            ));
        }

        try {
            $result = $this->doHandle($taskId, $params);

            if ($identity !== null) {
                $this->identityPool->release($identity);
            }

            return $result;
        } catch (IdentityBlockedException $e) {
            if ($identity !== null) {
                if ($identity->proxyType === 'rotating') {
                    $this->identityPool->release($identity);
                    $this->logger->info(sprintf(
                        'Identity %s (rotating) получила 403 — возвращена в пул (IP сменится автоматически, попытка %d/%d)',
                        substr($identity->id, 0, 8),
                        $attempt + 1,
                        $this->identityPoolConfig->maxIdentityRetries,
                    ));
                } else {
                    $this->identityPool->quarantine($identity);
                    $this->logger->warning(sprintf(
                        'Identity %s заблокирована (403), карантин (попытка %d/%d)',
                        substr($identity->id, 0, 8),
                        $attempt + 1,
                        $this->identityPoolConfig->maxIdentityRetries,
                    ));
                }
            }

            if ($attempt < $this->identityPoolConfig->maxIdentityRetries) {
                TraceContext::setIdentity(null);
                return $this->executeWithIdentity($taskId, $params, $attempt + 1);
            }

            throw new \RuntimeException(
                sprintf('Все identity заблокированы после %d попыток', $attempt + 1),
                0,
                $e,
            );
        } catch (\Throwable $e) {
            if ($identity !== null) {
                $this->identityPool->release($identity);
            }
            throw $e;
        } finally {
            TraceContext::setIdentity(null);
        }
    }

    /**
     * Основная логика парсинга одного товара (без обёртки identity).
     */
    private function doHandle(string $taskId, array $params): TaskResult
    {
        $marketplace = $this->marketplaceRegistry->get($params['marketplace'] ?? 'ozon');
        $apiClient = $marketplace->getApiClient();
        $productParser = $marketplace->getProductParser();
        $reviewParser = $marketplace->getReviewParser();
        $categoryExtractor = $marketplace->getCategoryExtractor();

        $externalId = (int) ($params['external_id'] ?? $params['ozon_id'] ?? 0);
        $slug = $params['slug'] ?? '';

        // Извлечение external_id и обрезка ID из slug (формат: {text}-{digits})
        // Обрезаем всегда, чтобы URL не дублировал: {slug}-{id}-{id}
        if ($slug !== '' && preg_match('/^(.+)-(\d{5,})$/', $slug, $matches)) {
            if ($externalId === 0) {
                $externalId = (int) $matches[2];
            }
            $slug = $matches[1];
            $this->logger->info(sprintf('Slug нормализован: external_id=%d, slug=%s', $externalId, $slug));
        }

        if ($externalId === 0) {
            throw new \RuntimeException(sprintf(
                'Невозможно определить external_id товара (slug=%s). Укажите ID явно или используйте slug в формате "название-123456"',
                $slug,
            ));
        }

        $mpName = $params['marketplace'] ?? 'ozon';
        $isPopulated = $this->productStorage->isProductPopulated($externalId, $mpName);

        // Умный rerun: если товар уже заполнен — пропускаем fetch, но собираем отзывы
        if ($isPopulated && ($params['skip_reviews'] ?? false)) {
            $this->logger->info(sprintf('Товар %d уже заполнен и skip_reviews=true, парсинг пропущен', $externalId));
            return new TaskResult(parsedItems: 0, skipped: true);
        }

        if ($isPopulated) {
            $this->logger->info(sprintf('Товар %d уже заполнен, пропускаем fetch — собираем только отзывы', $externalId));

            $reviewDtos = $this->reviewCollector->collect($apiClient, $reviewParser, $slug, $externalId);

            if (!empty($reviewDtos)) {
                $this->productStorage->saveReviewsForExistingProduct($externalId, $mpName, $reviewDtos, $taskId);
                $this->logger->info(sprintf('Отзывы для товара %d собраны: %d', $externalId, count($reviewDtos)));
            }

            $this->taskStorage->updateTaskProgress($taskId, 1, 1);
            $this->progressTracker->updateProgress($taskId, 1, 1, 'running');

            return new TaskResult(parsedItems: count($reviewDtos) > 0 ? 1 : 0);
        }

        $this->logger->info(sprintf('Парсинг товара: external_id=%d, slug=%s', $externalId, $slug));

        $this->taskStorage->updateTaskProgress($taskId, 0, 1);
        $this->progressTracker->updateProgress($taskId, 0, 1, 'running');

        // Шаг 1: Загрузка HTML page 1 (Schema.org, галерея, категории)
        // При 403 — OzonApiClient бросит IdentityBlockedException если есть identity
        $html = $apiClient->fetchProductHtml($slug, $externalId);
        $htmlData = $this->htmlParser->parseAll($html);
        $this->logger->info(sprintf(
            'HTML page 1: schemaOrg=%s, изображений=%d, категория=%s',
            $htmlData['schemaOrg'] !== null ? 'да' : 'нет',
            count($htmlData['galleryImages']),
            ($htmlData['category'] ?? null) !== null ? 'да' : 'нет',
        ));

        // Шаг 2: Загрузка API page 2 (характеристики, виджеты)
        $response = $apiClient->fetchProduct($slug, $externalId);

        // Шаг 3: Мерж данных HTML + API
        $product = $productParser->parse($response, $externalId, $slug, $htmlData);

        if ($product === null) {
            $this->dumpDebugData($externalId, $html, $response, $htmlData);
            throw new \RuntimeException(sprintf('Не удалось распарсить товар %d', $externalId));
        }

        $categoryId = null;
        $categories = $categoryExtractor->extract($response, $htmlData['category'] ?? null);
        if (!empty($categories)) {
            $categoryDtos = array_map(
                static fn (array $c) => CategoryData::fromArray($c),
                $categories,
            );
            $categoryIds = $this->categoryStorage->upsertCategories($categoryDtos, $taskId);
            $categoryId = end($categoryIds) ?: null;
        }

        $product['category_id'] = $categoryId;
        $productDto = ProductData::fromArray($product);

        $reviewDtos = ($params['skip_reviews'] ?? false)
            ? []
            : $this->reviewCollector->collect($apiClient, $reviewParser, $slug, $externalId);

        $productId = $this->productStorage->saveProductWithReviews($productDto, $reviewDtos, $taskId);

        $this->logger->info(sprintf(
            'Товар %d сохранён (id=%d), отзывов: %d',
            $externalId,
            $productId,
            count($reviewDtos),
        ));

        $this->taskStorage->updateTaskProgress($taskId, 1, 1);
        $this->progressTracker->updateProgress($taskId, 1, 1, 'running');

        return new TaskResult(parsedItems: 1);
    }

    /**
     * Сохраняет сырые данные (HTML, API-ответ, результат парсинга HTML) для отладки.
     */
    private function dumpDebugData(int $externalId, string $html, array $apiResponse, array $htmlData): void
    {
        try {
            $dir = '/app/var/debug/products/' . $externalId . '_' . date('Ymd_His');
            if (!is_dir($dir)) {
                mkdir($dir, 0o775, true);
            }

            file_put_contents($dir . '/page1.html', $html);
            file_put_contents($dir . '/page2_api.json', json_encode($apiResponse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            file_put_contents($dir . '/html_parsed.json', json_encode($htmlData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            $this->logger->warning(
                sprintf('Дамп сырых данных товара %d сохранён в %s', $externalId, $dir),
                ['channel' => 'parser'],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Не удалось сохранить дамп товара %d: %s', $externalId, $e->getMessage()),
                ['channel' => 'parser'],
            );
        }
    }
}
