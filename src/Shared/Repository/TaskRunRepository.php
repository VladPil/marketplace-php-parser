<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\TaskRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Репозиторий для работы с запусками задач.
 *
 * @extends ServiceEntityRepository<TaskRun>
 */
class TaskRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskRun::class);
    }

    /**
     * Возвращает запуски задачи, отсортированные по номеру (последние первыми).
     *
     * @param string $taskId Идентификатор задачи
     * @param int $limit Максимальное количество запусков
     * @return TaskRun[]
     */
    public function findByTaskId(string $taskId, int $limit = 5): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.taskId = :taskId')
            ->setParameter('taskId', $taskId)
            ->orderBy('r.runNumber', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Возвращает последний запуск задачи.
     */
    public function findLatestByTaskId(string $taskId): ?TaskRun
    {
        return $this->createQueryBuilder('r')
            ->where('r.taskId = :taskId')
            ->setParameter('taskId', $taskId)
            ->orderBy('r.runNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Возвращает следующий номер запуска для задачи.
     */
    public function getNextRunNumber(string $taskId): int
    {
        $result = $this->createQueryBuilder('r')
            ->select('MAX(r.runNumber)')
            ->where('r.taskId = :taskId')
            ->setParameter('taskId', $taskId)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $result) + 1;
    }
}
