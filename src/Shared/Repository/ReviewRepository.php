<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\Review;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /**
     * Получает отзывы, найденные конкретной задачей парсинга.
     *
     * @return Review[]
     */
    public function findByTaskId(string $taskId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.parseTaskId = :taskId')
            ->setParameter('taskId', $taskId)
            ->orderBy('r.reviewDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Статистика по отзывам: общее кол-во, средний рейтинг, распределение по оценкам */
    public function getStats(): array
    {
        $row = $this->createQueryBuilder('r')
            ->select('COUNT(r.id) AS total', 'AVG(r.rating) AS avgRating')
            ->getQuery()
            ->getSingleResult();

        $ratingRows = $this->createQueryBuilder('r')
            ->select('r.rating, COUNT(r.id) AS cnt')
            ->groupBy('r.rating')
            ->orderBy('r.rating', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $ratingDistribution = [];
        foreach ($ratingRows as $r) {
            $ratingDistribution[(int) $r['rating']] = (int) $r['cnt'];
        }

        return [
            'total' => (int) $row['total'],
            'avgRating' => $row['avgRating'] !== null ? round((float) $row['avgRating'], 1) : null,
            'ratingDistribution' => $ratingDistribution,
        ];
    }

    /** @return Review[] */
    public function findByProduct(int $productId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.product = :productId')
            ->setParameter('productId', $productId)
            ->orderBy('r.reviewDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
