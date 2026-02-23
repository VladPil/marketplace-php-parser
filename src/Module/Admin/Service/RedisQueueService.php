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
}
