<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\SolverSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Репозиторий для работы с сессиями solver.
 *
 * @extends ServiceEntityRepository<SolverSession>
 */
class SolverSessionRepository extends ServiceEntityRepository
{
    private ManagerRegistry $managerRegistry;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SolverSession::class);
        $this->managerRegistry = $registry;
    }

    /**
     * Получает сессии для конкретной задачи парсинга.
     *
     * @param string $taskId Идентификатор задачи
     * @return SolverSession[]
     */
    public function findByTaskId(string $taskId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.parseTaskId = :taskId')
            ->setParameter('taskId', $taskId)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }


    /**
     * Агрегирует статистику solver-сессий по адресу прокси.
     *
     * Возвращает массив: адрес прокси => {total, success, error, lastUsed}.
     *
     * @return array<string, array{total: int, success: int, error: int, lastUsed: string|null}>
     */
    public function getStatsByProxy(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $rows = $conn->executeQuery(
            'SELECT proxy, status, COUNT(*) AS cnt, MAX(created_at) AS last_used
             FROM solver_sessions
             GROUP BY proxy, status'
        )->fetchAllAssociative();

        $stats = [];

        foreach ($rows as $row) {
            $proxy = $row['proxy'];

            if (!isset($stats[$proxy])) {
                $stats[$proxy] = ['total' => 0, 'success' => 0, 'error' => 0, 'lastUsed' => null];
            }

            $count = (int) $row['cnt'];
            $stats[$proxy]['total'] += $count;

            if ($row['status'] === 'success') {
                $stats[$proxy]['success'] += $count;
            } else {
                $stats[$proxy]['error'] += $count;
            }

            if ($row['last_used'] !== null) {
                if ($stats[$proxy]['lastUsed'] === null || $row['last_used'] > $stats[$proxy]['lastUsed']) {
                    $stats[$proxy]['lastUsed'] = $row['last_used'];
                }
            }
        }

        return $stats;
    }

    /**
     * Сохраняет сессию в БД.
     *
     * Использует ManagerRegistry для получения EM — при закрытом EM
     * (после предыдущей ошибки) сбрасывает и пересоздаёт менеджер.
     */
    public function save(SolverSession $session): void
    {
        $em = $this->getEntityManager();

        if (!$em->isOpen()) {
            $this->managerRegistry->resetManager();
            $em = $this->managerRegistry->getManagerForClass(SolverSession::class);
        }

        $em->persist($session);
        $em->flush();
    }
}
