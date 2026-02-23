<?php

declare(strict_types=1);

namespace App\Module\Parser\Worker;

use App\Module\Parser\Config\ParserConfig;
use App\Module\Parser\Marketplace\MarketplaceRegistry;
use App\Shared\Contract\CategoryStorageInterface;
use App\Shared\Contract\ProductStorageInterface;
use App\Shared\Contract\ProgressTrackerInterface;
use App\Shared\Contract\TaskHandlerInterface;
use App\Shared\Contract\TaskStorageInterface;
use App\Shared\DTO\CategoryData;
use App\Shared\DTO\ProductData;
use App\Shared\DTO\ReviewData;
use App\Shared\DTO\TaskResult;
use App\Module\Parser\Marketplace\Ozon\OzonHtmlParser;
use App\Shared\Logging\ParseLogger;

/**
 * Обработчик задач парсинга одного товара.
 *
 * Выполняет 2 запроса:
 * 1. HTML page 1 (SSR) — Schema.org, галерея, категории
 * 2. API page 2 (JSON) — характеристики, описание, отзывы
 */
final class ProductTaskHandler implements TaskHandlerInterface
{
    public function __construct(
        private readonly MarketplaceRegistry $marketplaceRegistry,
        private readonly ProductStorageInterface $productStorage,
        private readonly CategoryStorageInterface $categoryStorage,
        private readonly TaskStorageInterface $taskStorage,
        private readonly ProgressTrackerInterface $progressTracker,
        private readonly ParserConfig $parserConfig,
        private readonly OzonHtmlParser $htmlParser,
        private readonly ParseLogger $logger,
    ) {}

    /** {@inheritdoc} */
    public function supports(string $taskType): bool
    {
        return $taskType === 'product';
    }

    /**
     * Обрабатывает задачу парсинга одного товара.
     *
     * @param string $taskId Идентификатор задачи
     * @param array $params Параметры (external_id, slug, marketplace)
     */
    public function handle(string $taskId, array $params): TaskResult
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
        if ($this->productStorage->isProductPopulated($externalId, $mpName)) {
            $this->logger->info(sprintf('Товар %d уже заполнен, парсинг пропущен', $externalId));
            return new TaskResult(parsedItems: 0);
        }

        $this->logger->info(sprintf('Парсинг товара: external_id=%d, slug=%s', $externalId, $slug));

        $this->taskStorage->updateTaskProgress($taskId, 0, 1);
        $this->progressTracker->updateProgress($taskId, 0, 1, 'running');

        // Шаг 1: Загрузка HTML page 1 (Schema.org, галерея, категории)
        // При 403 — fetchProductHtml бросит RuntimeException, задача сразу failed
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
            throw new \RuntimeException(sprintf('Не удалось распарсить товар %d', $externalId));
        }

        $categoryId = null;
        $categories = $categoryExtractor->extract($response, $htmlData['category'] ?? null);
        if (!empty($categories)) {
            $categoryDtos = array_map(
                static fn(array $c) => CategoryData::fromArray($c),
                $categories,
            );
            $categoryIds = $this->categoryStorage->upsertCategories($categoryDtos, $taskId);
            $categoryId = end($categoryIds) ?: null;
        }

        $product['category_id'] = $categoryId;
        $productDto = ProductData::fromArray($product);

        $reviews = [];
        if (!($params['skip_reviews'] ?? false)) {
            $reviewResponse = $apiClient->fetchReviewsFirstPage($slug, $externalId);
            $reviewPageNum = 1;
            while (true) {
                $pageReviews = $reviewParser->parse($reviewResponse);
                $reviews = array_merge($reviews, $pageReviews);
                $reviewPageNum++;
                if ($reviewPageNum > $this->parserConfig->maxReviewPages) {
                    break;
                }
                $nextPageUrl = $reviewParser->getNextPageUrl($reviewResponse);
                if ($nextPageUrl === null) {
                    break;
                }
                $reviewResponse = $apiClient->fetchReviewsByNextPage($nextPageUrl);
            }
        }

        $reviewDtos = array_map(
            static fn(array $r) => ReviewData::fromArray($r),
            $reviews,
        );

        $productId = $this->productStorage->saveProductWithReviews($productDto, $reviewDtos, $taskId);

        $this->logger->info(sprintf(
            'Товар %d сохранён (id=%d), отзывов: %d',
            $externalId,
            $productId,
            count($reviews),
        ));

        $this->taskStorage->updateTaskProgress($taskId, 1, 1);
        $this->progressTracker->updateProgress($taskId, 1, 1, 'running');

        return new TaskResult(parsedItems: 1);
    }
}
