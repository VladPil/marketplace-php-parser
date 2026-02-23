<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /** @return Category[] */
    public function findRootCategories(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.parent IS NULL')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Category[] */
    public function findTree(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.depth', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Общее количество категорий */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Category[] Категории, привязанные к задаче парсинга */
    public function findByTaskId(string $taskId): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.parseTaskId = :taskId')
            ->setParameter('taskId', $taskId)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
