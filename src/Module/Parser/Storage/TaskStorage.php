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
    ) {
    }

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


    /**
     * Создаёт новый запуск для задачи.
     *
     * @return string ID созданного запуска
     */
    public function createRun(string $taskId): string
    {
        return $this->withConnection(function (\PDO $pdo) use ($taskId): string {
            // Определяем следующий номер запуска
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(run_number), 0) + 1 FROM task_runs WHERE task_id = :task_id');
            $stmt->execute(['task_id' => $taskId]);
            $runNumber = (int) $stmt->fetchColumn();

            $runId = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
            $stmt = $pdo->prepare(
                'INSERT INTO task_runs (id, task_id, run_number, status, created_at)
                 VALUES (:id, :task_id, :run_number, :status, NOW())'
            );
            $stmt->execute([
                'id' => $runId,
                'task_id' => $taskId,
                'run_number' => $runNumber,
                'status' => 'pending',
            ]);

            return $runId;
        });
    }

    /**
     * Обновляет статус запуска.
     */
    public function updateRunStatus(string $runId, string $status, ?string $error = null, ?int $parsedItems = null): void
    {
        $this->withConnection(function (\PDO $pdo) use ($runId, $status, $error, $parsedItems): void {
            $fields = ['status = :status'];
            $params = ['status' => $status, 'run_id' => $runId];

            if ($status === 'running') {
                $fields[] = 'started_at = NOW()';
            }
            if (in_array($status, ['completed_success', 'completed_empty', 'completed_skipped', 'completed_partial', 'failed', 'cancelled'], true)) {
                $fields[] = 'finished_at = NOW()';
            }
            if ($error !== null) {
                $fields[] = 'error = :error';
                $params['error'] = $error;
            }
            if ($parsedItems !== null) {
                $fields[] = 'parsed_items = :parsed_items';
                $params['parsed_items'] = $parsedItems;
            }

            $sql = sprintf('UPDATE task_runs SET %s WHERE id = :run_id', implode(', ', $fields));
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        });
    }

    /**
     * Обновляет прогресс запуска.
     */
    public function updateRunProgress(string $runId, int $parsedItems): void
    {
        $this->withConnection(function (\PDO $pdo) use ($runId, $parsedItems): void {
            $stmt = $pdo->prepare('UPDATE task_runs SET parsed_items = :parsed WHERE id = :run_id');
            $stmt->execute(['parsed' => $parsedItems, 'run_id' => $runId]);
        });
    }

    /**
     * Сохраняет identity_id в запуске.
     */
    public function setRunIdentity(string $runId, string $identityId): void
    {
        $this->withConnection(function (\PDO $pdo) use ($runId, $identityId): void {
            $stmt = $pdo->prepare('UPDATE task_runs SET identity_id = :identity_id WHERE id = :run_id');
            $stmt->execute(['identity_id' => $identityId, 'run_id' => $runId]);
        });
    }
}
