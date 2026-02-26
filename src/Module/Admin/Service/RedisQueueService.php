<?php

declare(strict_types=1);

namespace App\Module\Admin\Service;

use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * Сервис для работы с Redis-очередями из admin-панели.
 *
 * Использует Symfony RedisAdapter для создания соединения
 * вместо ручного управления подключениями.
 */
final class RedisQueueService
{
    private \Redis $redis;

    /**
     * @param string $redisDsn DSN подключения к Redis (redis://host:port)
     */
    public function __construct(string $redisDsn = 'redis://redis:6379')
    {
        $this->redis = RedisAdapter::createConnection($redisDsn);
    }

    /**
     * Публикует задачу парсинга в Redis-очередь.
     *
     * @param array $task Данные задачи (id, type, params, marketplace)
     */
    public function publishTask(array $task): void
    {
        $this->redis->lPush('mp:parse:tasks', json_encode($task));
    }

    /**
     * Получает прогресс выполнения задачи из Redis.
     *
     * @param string $taskId Идентификатор задачи
     * @return array<string, string>
     */
    public function getProgress(string $taskId): array
    {
        /** @var array<string, string>|false $result */
        $result = $this->redis->hGetAll('mp:parse:progress:' . $taskId);
        return is_array($result) ? $result : [];
    }

    /**
     * Удаляет задачи из Redis-очереди по списку ID.
     *
     * Сканирует очередь и удаляет элементы, чей id совпадает.
     *
     * @param string[] $taskIds
     * @return int Количество удалённых элементов
     */
    public function removeTasks(array $taskIds): int
    {
        if (empty($taskIds)) {
            return 0;
        }

        $queueKey = 'mp:parse:tasks';
        $lookup = array_flip($taskIds);
        $removed = 0;

        $items = $this->redis->lRange($queueKey, 0, -1);

        if (!is_array($items)) {
            return 0;
        }

        foreach ($items as $raw) {
            $data = json_decode($raw, true);

            if (is_array($data) && isset($data['id']) && isset($lookup[$data['id']])) {
                $removed += (int) $this->redis->lRem($queueKey, $raw, 0);
            }
        }

        return $removed;
    }
}
