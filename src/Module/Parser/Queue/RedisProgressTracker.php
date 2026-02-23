<?php

declare(strict_types=1);

namespace App\Module\Parser\Queue;

use App\Module\Parser\Config\RedisConfig;
use App\Shared\Contract\ProgressTrackerInterface;
use App\Shared\Infrastructure\WithRedisConnectionTrait;

final class RedisProgressTracker implements ProgressTrackerInterface
{
    use WithRedisConnectionTrait;

    public function __construct(
        private readonly RedisConnectionPool $pool,
        private readonly RedisConfig $config,
    ) {}

    public function updateProgress(string $taskId, int $parsed, int $total, string $status): void
    {
        $this->withRedis(function (\Redis $redis) use ($taskId, $parsed, $total, $status): void {
            $key = $this->config->prefix . 'progress:' . $taskId;
            $redis->hMSet($key, [
                'parsed' => $parsed,
                'total' => $total,
                'status' => $status,
                'updated_at' => time(),
            ]);
            $redis->expire($key, 86400);
        });
    }

    public function getProgress(string $taskId): array
    {
        return $this->withRedis(function (\Redis $redis) use ($taskId): array {
            $key = $this->config->prefix . 'progress:' . $taskId;
            /** @var array<string, string>|false $result */
            $result = $redis->hGetAll($key);
            if (!is_array($result) || empty($result)) {
                return ['parsed' => 0, 'total' => 0, 'status' => 'unknown', 'updated_at' => 0];
            }

            return [
                'parsed' => (int) ($result['parsed'] ?? 0),
                'total' => (int) ($result['total'] ?? 0),
                'status' => $result['status'] ?? 'unknown',
                'updated_at' => (int) ($result['updated_at'] ?? 0),
            ];
        });
    }
}
