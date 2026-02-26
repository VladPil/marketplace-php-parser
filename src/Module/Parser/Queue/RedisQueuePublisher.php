<?php

declare(strict_types=1);

namespace App\Module\Parser\Queue;

use App\Module\Parser\Config\RedisConfig;
use App\Shared\Contract\QueuePublisherInterface;
use App\Shared\Infrastructure\WithRedisConnectionTrait;

final class RedisQueuePublisher implements QueuePublisherInterface
{
    use WithRedisConnectionTrait;

    public function __construct(
        private readonly RedisConnectionPool $pool,
        private readonly RedisConfig $config,
    ) {}

    public function requeueTask(array $task): void
    {
        $this->withRedis(function (\Redis $redis) use ($task): void {
            $redis->lPush($this->config->prefix . 'tasks', json_encode($task));
        });
    }
}
