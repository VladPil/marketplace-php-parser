<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\Image;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Image>
 */
class ImageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Image::class);
    }

    /** @return Image[] Изображения сущности, отсортированные по sort_order */
    public function findByEntity(string $entityType, int $entityId): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.entityType = :type')
            ->andWhere('i.entityId = :id')
            ->setParameter('type', $entityType)
            ->setParameter('id', $entityId)
            ->orderBy('i.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Image[] Изображения, привязанные к задаче парсинга */
    public function findByTaskId(string $taskId): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.parseTaskId = :taskId')
            ->setParameter('taskId', $taskId)
            ->orderBy('i.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
