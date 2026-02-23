<?php

declare(strict_types=1);

namespace App\Module\Admin\Service;

use App\Shared\Entity\ParseLog;
use App\Shared\Entity\ParseTask;
use Doctrine\ORM\EntityManagerInterface;

final class ParseTaskService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RedisQueueService $queueService,
    ) {}

    public function createTask(string $type, array $params, string $marketplace = 'ozon', bool $publishToQueue = true): ParseTask
    {
        $task = new ParseTask();
        $task->setType($type);
        $task->setParams($params);
        $task->setMarketplace($marketplace);

        $this->em->persist($task);
        $this->em->flush();

        if ($publishToQueue) {
            $this->queueService->publishTask([
                'id' => $task->getId(),
                'type' => $type,
                'params' => array_merge($params, ['marketplace' => $marketplace]),
                'marketplace' => $marketplace,
            ]);
        }
        return $task;
    }

    public function createReviewsTaskWithProductSubtask(string $type, array $params, string $marketplace = 'ozon'): ParseTask
    {
        $parent = $this->createTask($type, $params, $marketplace, publishToQueue: false);

        $childParams = [
            'external_id' => $params['external_id'] ?? 0,
            'slug' => $params['slug'] ?? '',
            'skip_reviews' => true,
        ];

        $child = new ParseTask();
        $child->setType('product');
        $child->setParams($childParams);
        $child->setMarketplace($marketplace);
        $child->setParentTaskId($parent->getId());

        $this->em->persist($child);
        $this->em->flush();
        $this->queueService->publishTask([
            'id' => $child->getId(),
            'type' => 'product',
            'params' => array_merge($childParams, ['marketplace' => $marketplace]),
            'marketplace' => $marketplace,
        ]);

        return $parent;
    }

    public function cancelTask(string $taskId): void
    {
        $task = $this->em->getRepository(ParseTask::class)->find($taskId);
        if ($task === null || !in_array($task->getStatus(), ['pending', 'running', 'paused'], true)) {
            return;
        }

        $task->setStatus('cancelled');

        /** @var \App\Shared\Repository\ParseTaskRepository $repo */
        $repo = $this->em->getRepository(ParseTask::class);
        $childTasks = $repo->findByParentTaskId($taskId);
        foreach ($childTasks as $child) {
            if (in_array($child->getStatus(), ['pending', 'running', 'paused'], true)) {
                $child->setStatus('cancelled');
            }
        }
            $this->em->flush();
    }

    /**
     * Удаляет задачу вместе со всеми связанными логами.
     */
    public function deleteTask(string $taskId): bool
    {
        $task = $this->em->getRepository(ParseTask::class)->find($taskId);

        if ($task === null) {
            return false;
        }

        /** @var \App\Shared\Repository\ParseTaskRepository $repo */
        $repo = $this->em->getRepository(ParseTask::class);
        $childIds = $repo->findChildTaskIds($taskId);
        $allIds = array_merge([$taskId], $childIds);
        $this->em->createQueryBuilder()
            ->delete(ParseLog::class, 'l')
            ->where('l.parseTaskId IN (:ids)')
            ->setParameter('ids', $allIds)
            ->getQuery()
            ->execute();
        $this->em->remove($task);
        $this->em->flush();
        return true;
    }

    /**
     * Массовое удаление задач и их логов.
     *
     * @param string[] $taskIds Идентификаторы задач
     * @return int Количество удалённых задач
     */
    public function deleteTasks(array $taskIds): int
    {
        if (empty($taskIds)) {
            return 0;
        }

        /** @var \App\Shared\Repository\ParseTaskRepository $repo */
        $repo = $this->em->getRepository(ParseTask::class);

        $allChildIds = [];
        foreach ($taskIds as $id) {
            $allChildIds = array_merge($allChildIds, $repo->findChildTaskIds($id));
        }
        $allIds = array_unique(array_merge($taskIds, $allChildIds));
        $this->em->createQueryBuilder()
            ->delete(ParseLog::class, 'l')
            ->where('l.parseTaskId IN (:ids)')
            ->setParameter('ids', $allIds)
            ->getQuery()
            ->execute();
        $deleted = $this->em->createQueryBuilder()
            ->delete(ParseTask::class, 't')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $allIds)
            ->getQuery()
            ->execute();
        return (int) $deleted;
    }
}
