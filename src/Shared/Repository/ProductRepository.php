<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /** @return Product[] */
    public function findByCategory(int $categoryId, int $limit = 50): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.category = :categoryId')
            ->setParameter('categoryId', $categoryId)
            ->orderBy('p.rating', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Получает товары, найденные конкретной задачей парсинга.
     *
     * @return Product[]
     */
    public function findByTaskId(string $taskId): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.parseTaskId = :taskId')
            ->setParameter('taskId', $taskId)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Статистика по товарам: общее кол-во, средняя цена, средний рейтинг, сумма отзывов */
    public function getStats(): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $row = $conn->fetchAssociative(
            'SELECT COUNT(*) AS total,
                    AVG(price::float) AS avg_price,
                    AVG(rating::float) AS avg_rating,
                    SUM(review_count) AS total_reviews
             FROM products'
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'avgPrice' => $row['avg_price'] !== null ? round((float) $row['avg_price'], 0) : null,
            'avgRating' => $row['avg_rating'] !== null ? round((float) $row['avg_rating'], 1) : null,
            'totalReviews' => (int) ($row['total_reviews'] ?? 0),
        ];
    }

    /** @return Product[] */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
