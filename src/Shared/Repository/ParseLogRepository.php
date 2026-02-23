<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\ParseLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Репозиторий для работы с логами парсинга.
 *
 * @extends ServiceEntityRepository<ParseLog>
 */
class ParseLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParseLog::class);
    }

    /**
     * Получает логи для конкретной задачи парсинга.
     *
     * @param string $taskId Идентификатор задачи
     * @param int $limit Максимальное количество записей
     * @return ParseLog[]
     */
    public function findByTaskId(string $taskId, int $limit = 500): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.parseTaskId = :taskId')
            ->setParameter('taskId', $taskId)
            ->orderBy('l.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Получает логи по trace_id для отслеживания сквозного запроса.
     *
     * @param string $traceId Идентификатор трассировки
     * @param int $limit Максимальное количество записей
     * @return ParseLog[]
     */
    public function findByTraceId(string $traceId, int $limit = 500): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.traceId = :traceId')
            ->setParameter('traceId', $traceId)
            ->orderBy('l.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Получает последние логи с опциональной фильтрацией по уровню.
     *
     * @param int $limit Максимальное количество записей
     * @param string|null $level Фильтр по уровню лога
     * @return ParseLog[]
     */
    public function findRecent(int $limit = 100, ?string $level = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($level !== null) {
            $qb->where('l.level = :level')->setParameter('level', $level);
        }

        return $qb->getQuery()->getResult();
    }
}
