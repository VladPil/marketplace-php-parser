<?php

declare(strict_types=1);

namespace App\Module\Parser\Storage;

use App\Shared\Contract\TaskStorageInterface;
use App\Shared\Infrastructure\WithPgConnectionTrait;

final class TaskStorage implements TaskStorageInterface
{
    use WithPgConnectionTrait;

    public function __construct(
        private readonly PgConnectionPool $pool,
    ) {}

    public function updateTaskStatus(string $taskId, string $status, ?string $errorMessage = null): void
    {
        $this->withConnection(function (\PDO $pdo) use ($taskId, $status, $errorMessage): void {
            $fields = ['status = :status'];
            $params = ['status' => $status, 'task_id' => $taskId];

            if ($status === 'running') {
                $fields[] = 'started_at = NOW()';
            }
            if (in_array($status, ['completed_success', 'completed_empty', 'completed_skipped', 'completed_partial', 'failed', 'cancelled'], true)) {
                $fields[] = 'completed_at = NOW()';
            }
            if ($errorMessage !== null) {
                $fields[] = 'error_message = :error_message';
                $params['error_message'] = $errorMessage;
            }

            $sql = sprintf('UPDATE parse_tasks SET %s WHERE id = :task_id', implode(', ', $fields));
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        });
    }

    public function updateTaskProgress(string $taskId, int $parsed, int $total): void
    {
        $this->withConnection(function (\PDO $pdo) use ($taskId, $parsed, $total): void {
            $stmt = $pdo->prepare(
                'UPDATE parse_tasks SET parsed_items = :parsed, total_items = :total WHERE id = :task_id'
            );
            $stmt->execute(['parsed' => $parsed, 'total' => $total, 'task_id' => $taskId]);
        });
    }

    public function saveResumeState(string $taskId, array $state): void
    {
        $this->withConnection(function (\PDO $pdo) use ($taskId, $state): void {
            $stmt = $pdo->prepare(
                'UPDATE parse_tasks SET resume_state = :state WHERE id = :task_id'
            );
            $stmt->execute(['state' => json_encode($state), 'task_id' => $taskId]);
        });
    }

    public function getResumableTasks(): array
    {
        return $this->withConnection(function (\PDO $pdo): array {
            $stmt = $pdo->query(
                "SELECT id, type, params, resume_state, marketplace FROM parse_tasks WHERE status = 'paused' ORDER BY created_at"
            );
            return $stmt->fetchAll();
        });
    }

    public function getChildTasks(string $parentTaskId): array
    {
        return $this->withConnection(function (\PDO $pdo) use ($parentTaskId): array {
            $stmt = $pdo->prepare(
                'SELECT id, type, status, params::text, marketplace FROM parse_tasks WHERE parent_task_id = :parent_task_id'
            );
            $stmt->execute(['parent_task_id' => $parentTaskId]);
            return $stmt->fetchAll();
        });
    }

    public function areAllChildrenCompleted(string $parentTaskId): bool
    {
        return $this->withConnection(function (\PDO $pdo) use ($parentTaskId): bool {
            $stmt = $pdo->prepare(
                "SELECT NOT EXISTS(
                    SELECT 1 FROM parse_tasks
                    WHERE parent_task_id = :parent_task_id
                    AND status NOT IN ('completed_success','completed_empty','completed_skipped','completed_partial','failed','cancelled')
                ) AND EXISTS(SELECT 1 FROM parse_tasks WHERE parent_task_id = :parent_task_id2)"
            );
            $stmt->execute(['parent_task_id' => $parentTaskId, 'parent_task_id2' => $parentTaskId]);
            return (bool) $stmt->fetchColumn();
        });
    }

    public function getParentTaskId(string $taskId): ?string
    {
        return $this->withConnection(function (\PDO $pdo) use ($taskId): ?string {
            $stmt = $pdo->prepare(
                'SELECT parent_task_id FROM parse_tasks WHERE id = :task_id'
            );
            $stmt->execute(['task_id' => $taskId]);
            $result = $stmt->fetchColumn();
            return $result !== false ? $result : null;
        });
    }

    public function getParentTaskAsQueueMessage(string $parentTaskId): ?array
    {
        return $this->withConnection(function (\PDO $pdo) use ($parentTaskId): ?array {
            $stmt = $pdo->prepare(
                'SELECT id, type, params::text, marketplace FROM parse_tasks WHERE id = :parent_task_id'
            );
            $stmt->execute(['parent_task_id' => $parentTaskId]);
            $task = $stmt->fetch();
            if ($task === false) {
                return null;
            }
            $task['params'] = json_decode($task['params'], true);
            return $task;
        });
    }

    public function taskExists(string $taskId): bool
    {
        return $this->withConnection(function (\PDO $pdo) use ($taskId): bool {
            $stmt = $pdo->prepare('SELECT EXISTS(SELECT 1 FROM parse_tasks WHERE id = :task_id)');
            $stmt->execute(['task_id' => $taskId]);
            return (bool) $stmt->fetchColumn();
        });
    }

}
