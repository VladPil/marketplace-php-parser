<?php

declare(strict_types=1);

namespace App\Module\Parser\Proxy;

use App\Module\Parser\Proxy\Config\ProxyRotationConfig;
use App\Module\Parser\Queue\RedisConnectionPool;
use App\Shared\Infrastructure\WithRedisConnectionTrait;
use App\Shared\Logging\ParseLogger;

/**
 * Трекер здоровья прокси через Redis sorted set.
 *
 * Score: 0.0 (мёртвый) — 1.0 (полностью здоровый).
 * Новые прокси начинают с 1.0.
 */
final class ProxyHealthTracker implements ProxyHealthTrackerInterface
{
    use WithRedisConnectionTrait;

    private const REDIS_KEY = 'mp:proxy:health';
    private const SUCCESS_DELTA = 0.1;
    private const FAILURE_DELTA = 0.3;

    public function __construct(
        private readonly ProxyRotationConfig $config,
        private readonly RedisConnectionPool $pool,
        private readonly ParseLogger $logger,
    ) {}

    public function recordSuccess(string $proxyId): void
    {
        try {
            $this->withRedis(function (\Redis $redis) use ($proxyId): void {
                $current = $redis->zScore(self::REDIS_KEY, $proxyId);
                if ($current === false) {
                    $redis->zAdd(self::REDIS_KEY, 1.0, $proxyId);
                    return;
                }
                $newScore = min(1.0, $current + self::SUCCESS_DELTA);
                $redis->zAdd(self::REDIS_KEY, $newScore, $proxyId);
            });
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[proxy] Ошибка записи success в Redis для %s: %s', $proxyId, $e->getMessage()),
                ['channel' => 'proxy'],
            );
        }
    }

    public function recordFailure(string $proxyId): void
    {
        try {
            $this->withRedis(function (\Redis $redis) use ($proxyId): void {
                $current = $redis->zScore(self::REDIS_KEY, $proxyId);
                if ($current === false) {
                    $redis->zAdd(self::REDIS_KEY, max(0.0, 1.0 - self::FAILURE_DELTA), $proxyId);
                    return;
                }
                $newScore = max(0.0, $current - self::FAILURE_DELTA);
                $redis->zAdd(self::REDIS_KEY, $newScore, $proxyId);
            });
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[proxy] Ошибка записи failure в Redis для %s: %s', $proxyId, $e->getMessage()),
                ['channel' => 'proxy'],
            );
        }
    }

    /**
     * Возвращает health score для прокси (0.0-1.0). Новый прокси = 1.0.
     */
    public function getHealthScore(string $proxyId): float
    {
        try {
            return $this->withRedis(static function (\Redis $redis) use ($proxyId): float {
                $score = $redis->zScore(self::REDIS_KEY, $proxyId);
                return $score === false ? 1.0 : (float) $score;
            });
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[proxy] Ошибка чтения health score из Redis для %s: %s', $proxyId, $e->getMessage()),
                ['channel' => 'proxy'],
            );
            return 1.0;
        }
    }

    /**
     * Проверяет, считается ли прокси здоровым.
     */
    public function isHealthy(string $proxyId): bool
    {
        return $this->getHealthScore($proxyId) >= $this->config->healthThreshold;
    }

    /**
     * Возвращает здоровые прокси из переданного списка.
     *
     * @param string[] $proxyIds
     * @return string[]
     */
    public function filterHealthy(array $proxyIds): array
    {
        return array_values(array_filter($proxyIds, fn(string $id): bool => $this->isHealthy($id)));
    }
}
