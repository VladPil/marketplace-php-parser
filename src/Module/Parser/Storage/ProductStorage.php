<?php

declare(strict_types=1);

namespace App\Module\Parser\Storage;

use App\Shared\Contract\ProductStorageInterface;
use App\Shared\DTO\ProductData;
use App\Shared\DTO\ReviewData;
use App\Shared\Infrastructure\WithPgConnectionTrait;

final class ProductStorage implements ProductStorageInterface
{
    use WithPgConnectionTrait;

    public function __construct(
        private readonly PgConnectionPool $pool,
    ) {}

    public function upsertProduct(ProductData $product, string $taskId): int
    {
        return $this->withConnection(function (\PDO $pdo) use ($product, $taskId): int {
            $stmt = $pdo->prepare(
                'INSERT INTO products (external_id, marketplace, title, url, price, original_price, rating, review_count, image_url, image_urls, characteristics, category_id, parse_task_id, brand, description)
                VALUES (:external_id, :marketplace, :title, :url, :price, :original_price, :rating, :review_count, :image_url, :image_urls, :characteristics, :category_id, :parse_task_id, :brand, :description)
                ON CONFLICT (marketplace, external_id) DO UPDATE SET
                    title = EXCLUDED.title,
                    url = EXCLUDED.url,
                    price = EXCLUDED.price,
                    original_price = EXCLUDED.original_price,
                    rating = EXCLUDED.rating,
                    review_count = EXCLUDED.review_count,
                    image_url = EXCLUDED.image_url,
                    image_urls = EXCLUDED.image_urls,
                    characteristics = CASE
                        WHEN EXCLUDED.characteristics != \'{}\'::jsonb
                        THEN EXCLUDED.characteristics
                        ELSE products.characteristics
                    END,
                    category_id = EXCLUDED.category_id,
                    parse_task_id = EXCLUDED.parse_task_id,
                    brand = COALESCE(EXCLUDED.brand, products.brand),
                    description = COALESCE(EXCLUDED.description, products.description)
                RETURNING id'
            );
            $stmt->execute([
                'external_id' => $product->external_id,
                'marketplace' => $product->marketplace,
                'title' => $product->title,
                'url' => $product->url,
                'price' => $product->price,
                'original_price' => $product->original_price,
                'rating' => $product->rating,
                'review_count' => $product->review_count,
                'image_url' => $product->image_url,
                'image_urls' => json_encode($product->image_urls),
                'characteristics' => json_encode($product->characteristics),
                'category_id' => $product->category_id,
                'parse_task_id' => $taskId,
                'brand' => $product->brand,
                'description' => $product->description,
            ]);
            return (int) $stmt->fetchColumn();
        });
    }

    public function ensureProductExists(int $externalId, string $marketplace, string $taskId): int
    {
        return $this->withConnection(function (\PDO $pdo) use ($externalId, $marketplace, $taskId): int {
            $stmt = $pdo->prepare(
                'INSERT INTO products (external_id, marketplace, title, parse_task_id)
                VALUES (:external_id, :marketplace, :title, :parse_task_id)
                ON CONFLICT (marketplace, external_id) DO NOTHING'
            );
            $stmt->execute([
                'external_id' => $externalId,
                'marketplace' => $marketplace,
                'title' => '',
                'parse_task_id' => $taskId,
            ]);

            // Возвращаем ID (независимо от того, была ли вставка)
            $selectStmt = $pdo->prepare(
                'SELECT id FROM products WHERE external_id = :external_id AND marketplace = :marketplace'
            );
            $selectStmt->execute(['external_id' => $externalId, 'marketplace' => $marketplace]);

            return (int) $selectStmt->fetchColumn();
        });
    }

    public function getProductIdByExternalId(int $externalId, string $marketplace = 'ozon'): ?int
    {
        return $this->withConnection(function (\PDO $pdo) use ($externalId, $marketplace): ?int {
            $stmt = $pdo->prepare(
                'SELECT id FROM products WHERE external_id = :external_id AND marketplace = :marketplace'
            );
            $stmt->execute(['external_id' => $externalId, 'marketplace' => $marketplace]);
            $id = $stmt->fetchColumn();
            return $id !== false ? (int) $id : null;
        });
    }

    public function isProductPopulated(int $externalId, string $marketplace): bool
    {
        return $this->withConnection(function (\PDO $pdo) use ($externalId, $marketplace): bool {
            $stmt = $pdo->prepare(
                'SELECT EXISTS(SELECT 1 FROM products WHERE external_id = :external_id AND marketplace = :marketplace AND title != \'\' AND title IS NOT NULL AND price IS NOT NULL)'
            );
            $stmt->execute(['external_id' => $externalId, 'marketplace' => $marketplace]);
            return (bool) $stmt->fetchColumn();
        });
    }

    public function saveProductWithReviews(ProductData $product, array $reviews, string $taskId): int
    {
        return $this->withConnection(function (\PDO $pdo) use ($product, $reviews, $taskId): int {
            $pdo->beginTransaction();
            try {
                $productId = $this->upsertProductInTransaction($pdo, $product, $taskId);

                if (!empty($reviews)) {
                    $reviewStmt = $pdo->prepare(
                        'INSERT INTO reviews (product_id, external_review_id, marketplace, parse_task_id, author, rating, text, pros, cons, review_date, image_urls, first_reply)
                        VALUES (:product_id, :external_review_id, :marketplace, :parse_task_id, :author, :rating, :text, :pros, :cons, :review_date, :image_urls, :first_reply)
                        ON CONFLICT (marketplace, external_review_id) DO NOTHING'
                    );
                    foreach ($reviews as $review) {
                        $reviewStmt->execute([
                            'product_id' => $productId,
                            'external_review_id' => $review->external_review_id,
                            'marketplace' => $review->marketplace,
                            'parse_task_id' => $taskId,
                            'author' => $review->author,
                            'rating' => $review->rating,
                            'text' => $review->text,
                            'pros' => $review->pros,
                            'cons' => $review->cons,
                            'review_date' => $review->review_date,
                            'image_urls' => json_encode($review->image_urls),
                            'first_reply' => $review->first_reply,
                        ]);
                    }
                }

                $pdo->commit();
                return $productId;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        });
    }

    public function saveReviewsForProduct(int $productId, array $reviews, string $taskId): void
    {
        $this->withConnection(function (\PDO $pdo) use ($productId, $reviews, $taskId): void {
            $pdo->beginTransaction();
            try {
                if (!empty($reviews)) {
                    $reviewStmt = $pdo->prepare(
                        'INSERT INTO reviews (product_id, external_review_id, marketplace, parse_task_id, author, rating, text, pros, cons, review_date, image_urls, first_reply)
                        VALUES (:product_id, :external_review_id, :marketplace, :parse_task_id, :author, :rating, :text, :pros, :cons, :review_date, :image_urls, :first_reply)
                        ON CONFLICT (marketplace, external_review_id) DO NOTHING'
                    );
                    foreach ($reviews as $review) {
                        $reviewStmt->execute([
                            'product_id' => $productId,
                            'external_review_id' => $review->external_review_id,
                            'marketplace' => $review->marketplace,
                            'parse_task_id' => $taskId,
                            'author' => $review->author,
                            'rating' => $review->rating,
                            'text' => $review->text,
                            'pros' => $review->pros,
                            'cons' => $review->cons,
                            'review_date' => $review->review_date,
                            'image_urls' => json_encode($review->image_urls),
                            'first_reply' => $review->first_reply,
                        ]);
                    }
                }

                // Обновляем только review_count, не трогая остальные поля товара
                $updateStmt = $pdo->prepare(
                    'UPDATE products SET review_count = (SELECT count(*) FROM reviews WHERE product_id = :product_id) WHERE id = :product_id'
                );
                $updateStmt->execute(['product_id' => $productId]);

                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        });
    }

    private function upsertProductInTransaction(\PDO $pdo, ProductData $product, string $taskId): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO products (external_id, marketplace, title, url, price, original_price, rating, review_count, image_url, image_urls, characteristics, category_id, parse_task_id, brand, description)
            VALUES (:external_id, :marketplace, :title, :url, :price, :original_price, :rating, :review_count, :image_url, :image_urls, :characteristics, :category_id, :parse_task_id, :brand, :description)
            ON CONFLICT (marketplace, external_id) DO UPDATE SET
                title = EXCLUDED.title,
                url = EXCLUDED.url,
                price = EXCLUDED.price,
                original_price = EXCLUDED.original_price,
                rating = EXCLUDED.rating,
                review_count = EXCLUDED.review_count,
                image_url = EXCLUDED.image_url,
                image_urls = EXCLUDED.image_urls,
                characteristics = CASE
                    WHEN EXCLUDED.characteristics != \'{}\'::jsonb
                    THEN EXCLUDED.characteristics
                    ELSE products.characteristics
                END,
                category_id = EXCLUDED.category_id,
                parse_task_id = EXCLUDED.parse_task_id,
                brand = COALESCE(EXCLUDED.brand, products.brand),
                description = COALESCE(EXCLUDED.description, products.description)
            RETURNING id'
        );
        $stmt->execute([
            'external_id' => $product->external_id,
            'marketplace' => $product->marketplace,
            'title' => $product->title,
            'url' => $product->url,
            'price' => $product->price,
            'original_price' => $product->original_price,
            'rating' => $product->rating,
            'review_count' => $product->review_count,
            'image_url' => $product->image_url,
            'image_urls' => json_encode($product->image_urls),
            'characteristics' => json_encode($product->characteristics),
            'category_id' => $product->category_id,
            'parse_task_id' => $taskId,
            'brand' => $product->brand,
            'description' => $product->description,
        ]);
        return (int) $stmt->fetchColumn();
    }
}
