<?php

declare(strict_types=1);

namespace App\Module\Parser\Queue;

use App\Module\Parser\Config\RedisConfig;
use App\Shared\Contract\QueueConsumerInterface;
use App\Shared\Infrastructure\WithRedisConnectionTrait;

final class RedisConsumer implements QueueConsumerInterface
{
    use WithRedisConnectionTrait;

    public function __construct(
        private readonly RedisConnectionPool $pool,
        private readonly RedisConfig $config,
    ) {}

    public function consume(float $timeout = 5.0): ?array
    {
        return $this->withRedis(function (\Redis $redis) use ($timeout): ?array {
            $result = $redis->brPop([$this->config->prefix . 'tasks'], (int) $timeout);

            if ($result === false || empty($result)) {
                return null;
            }

            $data = json_decode($result[1], true);
            return is_array($data) ? $data : null;
        });
    }

    public function acquireLock(string $taskId, int $ttl = 300): bool
    {
        return $this->withRedis(function (\Redis $redis) use ($taskId, $ttl): bool {
            $key = $this->config->prefix . 'lock:' . $taskId;
            return (bool) $redis->set($key, '1', ['NX', 'EX' => $ttl]);
        });
    }

    public function releaseLock(string $taskId): void
    {
        $this->withRedis(function (\Redis $redis) use ($taskId): void {
            $key = $this->config->prefix . 'lock:' . $taskId;
            $redis->del($key);
        });
    }
}
