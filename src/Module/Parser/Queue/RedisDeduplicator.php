<?php

declare(strict_types=1);

namespace App\Module\Parser\Queue;

use App\Module\Parser\Config\RedisConfig;
use App\Shared\Contract\DeduplicatorInterface;
use App\Shared\Infrastructure\WithRedisConnectionTrait;

final class RedisDeduplicator implements DeduplicatorInterface
{
    use WithRedisConnectionTrait;

    public function __construct(
        private readonly RedisConnectionPool $pool,
        private readonly RedisConfig $config,
    ) {}

    public function isProductSeen(string $taskId, int $externalId): bool
    {
        return $this->withRedis(function (\Redis $redis) use ($taskId, $externalId): bool {
            $key = $this->config->prefix . 'products:seen:' . $taskId;
            return (bool) $redis->sIsMember($key, (string) $externalId);
        });
    }

    public function markProductSeen(string $taskId, int $externalId): void
    {
        $this->withRedis(function (\Redis $redis) use ($taskId, $externalId): void {
            $key = $this->config->prefix . 'products:seen:' . $taskId;
            $added = $redis->sAdd($key, (string) $externalId);
            if ($added > 0) {
                $redis->expire($key, 86400);
            }
        });
    }

    public function isReviewSeen(string $taskId, string|int $reviewId): bool
    {
        return $this->withRedis(function (\Redis $redis) use ($taskId, $reviewId): bool {
            $key = $this->config->prefix . 'reviews:seen:' . $taskId;
            return (bool) $redis->sIsMember($key, (string) $reviewId);
        });
    }

    public function markReviewSeen(string $taskId, string|int $reviewId): void
    {
        $this->withRedis(function (\Redis $redis) use ($taskId, $reviewId): void {
            $key = $this->config->prefix . 'reviews:seen:' . $taskId;
            $added = $redis->sAdd($key, (string) $reviewId);
            if ($added > 0) {
                $redis->expire($key, 86400);
            }
        });
    }
}
