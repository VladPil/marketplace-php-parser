<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\ParseTask;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ParseTask>
 */
class ParseTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParseTask::class);
    }

    /** @return ParseTask[] */
    public function findRecentTasks(int $limit = 50): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return ParseTask[] */
    public function findByBatchId(string $batchId): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.batchId = :batchId')
            ->setParameter('batchId', $batchId)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Количество задач по статусам */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.status, COUNT(t.id) AS cnt')
            ->groupBy('t.status')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['status']] = (int) $row['cnt'];
        }

        return $result;
    }

    /** Количество задач по типам */
    public function countByType(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.type, COUNT(t.id) AS cnt')
            ->groupBy('t.type')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['type']] = (int) $row['cnt'];
        }

        return $result;
    }

    /**
     * Агрегация статусов задач батча.
     *
     * @return array{total: int, completed_success: int, failed: int, running: int, pending: int}
     */
    public function getBatchSummary(string $batchId): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.status, COUNT(t.id) AS cnt')
            ->where('t.batchId = :batchId')
            ->setParameter('batchId', $batchId)
            ->groupBy('t.status')
            ->getQuery()
            ->getArrayResult();

        $summary = [
            'total' => 0,
            'completed_success' => 0,
            'failed' => 0,
            'running' => 0,
            'pending' => 0,
        ];

        foreach ($rows as $row) {
            $count = (int) $row['cnt'];
            $summary['total'] += $count;

            if (isset($summary[$row['status']])) {
                $summary[$row['status']] = $count;
            }
        }

        return $summary;
    }

    /**
     * Возвращает ID подзадач указанной родительской задачи.
     *
     * @return string[]
     */
    public function findChildTaskIds(string $parentTaskId): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.id')
            ->where('t.parentTaskId = :parentTaskId')
            ->setParameter('parentTaskId', $parentTaskId)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'id');
    }

    /**
     * Возвращает подзадачи указанной родительской задачи.
     *
     * @return ParseTask[]
     */
    public function findByParentTaskId(string $parentTaskId): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.parentTaskId = :parentTaskId')
            ->setParameter('parentTaskId', $parentTaskId)
            ->orderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

}
