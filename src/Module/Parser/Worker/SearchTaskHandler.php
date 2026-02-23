<?php

declare(strict_types=1);

namespace App\Module\Parser\Worker;

use App\Module\Parser\Config\ParserConfig;
use App\Module\Parser\Marketplace\MarketplaceRegistry;
use App\Shared\Contract\CategoryStorageInterface;
use App\Shared\Contract\DeduplicatorInterface;
use App\Shared\Contract\ProductStorageInterface;
use App\Shared\Contract\ProgressTrackerInterface;
use App\Shared\Contract\TaskHandlerInterface;
use App\Shared\Contract\TaskStorageInterface;
use App\Shared\DTO\CategoryData;
use App\Shared\DTO\ProductData;
use App\Shared\DTO\ReviewData;
use App\Shared\DTO\TaskResult;
use App\Shared\Logging\ParseLogger;

/**
 * Обработчик задач поиска товаров на маркетплейсе.
 *
 * Выполняет полный цикл: поиск → парсинг товаров → сбор отзывов.
 * Логирует каждый этап с привязкой к trace_id.
 */
final class SearchTaskHandler implements TaskHandlerInterface
{
    public function __construct(
        private readonly MarketplaceRegistry $marketplaceRegistry,
        private readonly ProductStorageInterface $productStorage,
        private readonly CategoryStorageInterface $categoryStorage,
        private readonly TaskStorageInterface $taskStorage,
        private readonly ProgressTrackerInterface $progressTracker,
        private readonly DeduplicatorInterface $deduplicator,
        private readonly ParserConfig $parserConfig,
        private readonly ParseLogger $logger,
    ) {}

    /** {@inheritdoc} */
    public function supports(string $taskType): bool
    {
        return $taskType === 'search';
    }

    /**
     * Обрабатывает задачу поиска: находит товары, парсит каждый, собирает отзывы.
     *
     * @param string $taskId Идентификатор задачи
     * @param array $params Параметры поиска (query, max_products, marketplace)
     */
    public function handle(string $taskId, array $params): TaskResult
    {
        $marketplace = $this->marketplaceRegistry->get($params['marketplace'] ?? 'ozon');
        $apiClient = $marketplace->getApiClient();
        $searchParser = $marketplace->getSearchParser();
        $productParser = $marketplace->getProductParser();
        $reviewParser = $marketplace->getReviewParser();
        $categoryExtractor = $marketplace->getCategoryExtractor();

        $query = $params['query'] ?? '';
        $maxProducts = $params['max_products'] ?? 100;

        $this->logger->info(sprintf('Поиск товаров: query="%s", max=%d', $query, $maxProducts));

        $productQueue = [];
        $page = 1;

        while (count($productQueue) < $maxProducts) {
            $response = $apiClient->searchProducts($query, $page);
            $products = $searchParser->parse($response);

            if (empty($products)) {
                $this->logger->info(sprintf('Страница %d: товары не найдены, поиск завершён', $page));
                break;
            }

            foreach ($products as $product) {
                if (count($productQueue) >= $maxProducts) {
                    break;
                }
                if (!$this->deduplicator->isProductSeen($taskId, $product['external_id'])) {
                    $productQueue[] = $product;
                }
            }

            $this->logger->debug(sprintf('Страница %d: найдено %d товаров, в очереди %d', $page, count($products), count($productQueue)));

            $this->taskStorage->saveResumeState($taskId, [
                'phase' => 'search',
                'last_page' => $page,
                'queue_count' => count($productQueue),
            ]);

            if (!$searchParser->hasNextPage($response)) {
                break;
            }

            $page++;
        }

        $this->logger->info(sprintf('Найдено %d товаров, начинаем парсинг', count($productQueue)));
        $this->taskStorage->updateTaskProgress($taskId, 0, count($productQueue));
        $this->progressTracker->updateProgress($taskId, 0, count($productQueue), 'running');

        $parsedCount = 0;
        $errorCount = 0;

        foreach ($productQueue as $index => $productInfo) {
            try {
                $this->deduplicator->markProductSeen($taskId, $productInfo['external_id']);

                $slug = $this->extractSlug($productInfo['url'] ?? '');
                $response = $apiClient->fetchProduct($slug, $productInfo['external_id']);
                $product = $productParser->parse($response, $productInfo['external_id'], $slug);

                if ($product === null) {
                    $this->logger->warning(sprintf('Товар %d: не удалось распарсить', $productInfo['external_id']));
                    continue;
                }

                $categoryId = null;
                $categories = $categoryExtractor->extract($response);
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

                // Курсорная пагинация отзывов через nextPage
                $reviews = [];
                $reviewResponse = $apiClient->fetchReviewsFirstPage($slug, $productInfo['external_id']);
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

                $reviewDtos = array_map(
                    static fn(array $r) => ReviewData::fromArray($r),
                    $reviews,
                );

                $productId = $this->productStorage->saveProductWithReviews($productDto, $reviewDtos, $taskId);

                $parsedCount++;

                $this->logger->info(sprintf(
                    'Товар %d: сохранён (id=%d), отзывов: %d',
                    $productInfo['external_id'],
                    $productId,
                    count($reviews),
                ));

                $parsed = $index + 1;
                $this->taskStorage->updateTaskProgress($taskId, $parsed, count($productQueue));
                $this->progressTracker->updateProgress($taskId, $parsed, count($productQueue), 'running');

                $this->taskStorage->saveResumeState($taskId, [
                    'phase' => 'products',
                    'processed' => $parsed,
                ]);
            } catch (\Throwable $e) {
                $errorCount++;
                $this->logger->error(sprintf(
                    'Товар %d: ошибка — %s',
                    $productInfo['external_id'],
                    $e->getMessage(),
                ));
            }
        }

        return new TaskResult(parsedItems: $parsedCount, errorCount: $errorCount);
    }

    /**
     * Извлекает slug товара из URL.
     *
     * @param string $url URL товара (например, /product/name-12345/)
     * @return string Slug или пустая строка
     */
    private function extractSlug(string $url): string
    {
        if (preg_match('#/product/([^/]+)-\d+/#', $url, $matches)) {
            return $matches[1];
        }
        return '';
    }
}
