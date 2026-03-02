<?php

declare(strict_types=1);

namespace App\Module\Parser\Worker;

use App\Module\Parser\Config\ParserConfig;
use App\Module\Parser\Identity\Identity;
use App\Module\Parser\Identity\IdentityBlockedException;
use App\Module\Parser\Identity\IdentityPool;
use App\Module\Parser\Identity\IdentityPoolConfig;
use App\Module\Parser\Marketplace\MarketplaceRegistry;
use App\Shared\Contract\DeduplicatorInterface;
use App\Shared\Contract\ProductStorageInterface;
use App\Shared\Contract\ProgressTrackerInterface;
use App\Shared\Contract\TaskHandlerInterface;
use App\Shared\Contract\TaskStorageInterface;
use App\Shared\DTO\ReviewData;
use App\Shared\DTO\TaskResult;
use App\Shared\Logging\ParseLogger;
use App\Shared\Tracing\TraceContext;

/**
 * Обработчик задач сбора отзывов для товара.
 *
 * Загружает отзывы постранично, дедуплицирует и сохраняет.
 * Останавливается когда все отзывы на странице старше date_from.
 *
 * Работает через Identity Pool:
 *   claim identity → выполнить сбор отзывов → release identity
 *   При 403 (IdentityBlockedException) → quarantine → claim новую → retry
 */
final class ReviewsTaskHandler implements TaskHandlerInterface
{
    public function __construct(
        private readonly MarketplaceRegistry $marketplaceRegistry,
        private readonly ProductStorageInterface $productStorage,
        private readonly TaskStorageInterface $taskStorage,
        private readonly ProgressTrackerInterface $progressTracker,
        private readonly DeduplicatorInterface $deduplicator,
        private readonly ParserConfig $parserConfig,
        private readonly ParseLogger $logger,
        private readonly IdentityPool $identityPool,
        private readonly IdentityPoolConfig $identityPoolConfig,
    ) {}

    /** {@inheritdoc} */
    public function supports(string $taskType): bool
    {
        return $taskType === 'reviews';
    }

    /**
     * Обрабатывает задачу сбора отзывов для товара.
     *
     * @param string $taskId Идентификатор задачи
     * @param array $params Параметры (external_id, slug, max_pages, marketplace, date_from)
     */
    public function handle(string $taskId, array $params): TaskResult
    {
        return $this->executeWithIdentity($taskId, $params);
    }

    /**
     * Выполняет сбор отзывов с привязкой к identity из пула.
     *
     * @param int $attempt Текущая попытка (0-based)
     */
    private function executeWithIdentity(string $taskId, array $params, int $attempt = 0): TaskResult
    {
        $identity = $this->identityPool->claim($taskId);

        if ($identity !== null) {
            TraceContext::setIdentity($identity);
            $this->logger->info(sprintf(
                'Identity %s привязана к задаче отзывов (прокси: %s, попытка %d)',
                substr($identity->id, 0, 8),
                $identity->maskedProxy(),
                $attempt + 1,
            ));
        } else {
            $this->logger->warning(sprintf(
                'Нет готовых identity в пуле для задачи отзывов (попытка %d)',
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
                        'Identity %s заблокирована (403) в задаче отзывов, карантин (попытка %d/%d)',
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
                sprintf('Все identity заблокированы после %d попыток (задача отзывов)', $attempt + 1),
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
     * Основная логика сбора отзывов (без обёртки identity).
     */
    private function doHandle(string $taskId, array $params): TaskResult
    {
        $marketplace = $this->marketplaceRegistry->get($params['marketplace'] ?? 'ozon');
        $apiClient = $marketplace->getApiClient();
        $reviewParser = $marketplace->getReviewParser();

        $externalId = (int) ($params['external_id'] ?? $params['ozon_id'] ?? 0);
        $slug = $params['slug'] ?? '';

        // Обрезка ID из slug, чтобы URL не дублировал: {slug}-{id}-{id}
        if ($slug !== '' && preg_match('/^(.+)-(\d{5,})$/', $slug, $matches)) {
            if ($externalId === 0) {
                $externalId = (int) $matches[2];
            }
            $slug = $matches[1];
        }

        $maxPages = $params['max_pages'] ?? $this->parserConfig->maxReviewPages;

        // Граница по дате: по умолчанию 1 месяц назад
        $dateFrom = isset($params['date_from'])
            ? new \DateTimeImmutable($params['date_from'])
            : new \DateTimeImmutable('-1 month');

        $this->logger->info(sprintf(
            'Сбор отзывов: external_id=%d, max_pages=%d, date_from=%s',
            $externalId,
            $maxPages,
            $dateFrom->format('Y-m-d'),
        ));

        $allReviews = [];
        $stoppedByDate = false;

        $this->logger->info(sprintf('Загрузка первой страницы отзывов для товара %d', $externalId));
        $response = $apiClient->fetchReviewsFirstPage($slug, $externalId);

        // Debug: логируем структуру ответа для диагностики
        $widgetKeys = array_keys($response['widgetStates'] ?? []);
        $this->logger->debug(sprintf(
            'Ответ API: %d ключей в widgetStates, nextPage=%s, widgetKeys: %s',
            count($widgetKeys),
            isset($response['nextPage']) ? mb_substr($response['nextPage'], 0, 100) : 'null',
            implode(', ', $widgetKeys),
        ));
        $pageNum = 1;

        while (true) {
            $reviews = $reviewParser->parse($response);

            // Debug: если 0 отзывов, дампим ключи ответа для диагностики
            if (count($reviews) === 0) {
                $responseKeys = array_keys($response);
                $wsKeys = array_keys($response['widgetStates'] ?? []);
                $this->logger->warning(sprintf(
                    '0 отзывов на странице %d. response keys: [%s], widgetStates keys: [%s], response size: %d',
                    $pageNum,
                    implode(', ', $responseKeys),
                    implode(', ', $wsKeys),
                    strlen(json_encode($response)),
                ));
            }

            $freshOnPage = 0;
            $oldOnPage = 0;
            $duplicatesOnPage = 0;

            foreach ($reviews as $review) {
                if ($review['review_date'] !== null) {
                    try {
                        $reviewDate = new \DateTimeImmutable($review['review_date']);
                        if ($reviewDate < $dateFrom) {
                            $oldOnPage++;
                            continue;
                        }
                    } catch (\Throwable) {
                    }
                }

                $freshOnPage++;

                if (!$this->deduplicator->isReviewSeen($taskId, $review['external_review_id'])) {
                    $this->deduplicator->markReviewSeen($taskId, $review['external_review_id']);
                    $allReviews[] = $review;
                } else {
                    $duplicatesOnPage++;
                }
            }

            $this->logger->info(sprintf(
                'Страница %d/%d: %d отзывов (свежих: %d, старых: %d, дублей: %d), всего собрано: %d',
                $pageNum,
                $maxPages,
                count($reviews),
                $freshOnPage,
                $oldOnPage,
                $duplicatesOnPage,
                count($allReviews),
            ));

            $this->taskStorage->updateTaskProgress($taskId, $pageNum, $maxPages);
            $this->progressTracker->updateProgress($taskId, $pageNum, $maxPages, 'running');

            if (count($reviews) > 0 && $freshOnPage === 0) {
                $this->logger->info(sprintf(
                    'Все отзывы на странице %d старше %s — остановка пагинации',
                    $pageNum,
                    $dateFrom->format('Y-m-d'),
                ));
                $stoppedByDate = true;
                break;
            }

            if (count($reviews) === 0) {
                $this->logger->info('Пустая страница — отзывы закончились');
                break;
            }

            $pageNum++;
            if ($pageNum > $maxPages) {
                $this->logger->info(sprintf('Достигнут лимит страниц: %d', $maxPages));
                break;
            }

            $nextPageUrl = $reviewParser->getNextPageUrl($response);
            if ($nextPageUrl === null) {
                $this->logger->info('Следующая страница не найдена — пагинация завершена');
                break;
            }

            $this->logger->info(sprintf('Загрузка страницы %d отзывов', $pageNum));
            $response = $apiClient->fetchReviewsByNextPage($nextPageUrl);
        }

        $mpName = $params['marketplace'] ?? 'ozon';

        if (!empty($allReviews)) {
            $productId = $this->productStorage->ensureProductExists($externalId, $mpName, $taskId);

            $reviewDtos = array_map(
                static fn(array $r) => ReviewData::fromArray($r),
                $allReviews,
            );
            $this->productStorage->saveReviewsForProduct($productId, $reviewDtos, $taskId);

            $this->logger->info(sprintf(
                'Собрано %d отзывов для товара %d%s',
                count($allReviews),
                $externalId,
                $stoppedByDate ? ' (остановлено по дате)' : '',
            ));
        } else {
            $this->logger->info(sprintf('Отзывов для товара %d не найдено', $externalId));
        }

        return new TaskResult(parsedItems: count($allReviews));
    }
}
