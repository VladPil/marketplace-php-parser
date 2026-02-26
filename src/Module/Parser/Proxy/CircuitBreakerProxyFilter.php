<?php

declare(strict_types=1);

namespace App\Module\Parser\Proxy;

use App\Module\Parser\Proxy\Config\ProxyRotationConfig;
use App\Module\Parser\Queue\RedisConnectionPool;
use App\Shared\Infrastructure\WithRedisConnectionTrait;
use App\Shared\Logging\ParseLogger;
use LeoCarmo\CircuitBreaker\CircuitBreaker;
use LeoCarmo\CircuitBreaker\Adapters\RedisAdapter;

/**
 * Circuit breaker для прокси через leocarmo/circuit-breaker-php.
 *
 * Per-proxy CB instance. Threshold = 3 ошибки (абсолютный счётчик).
 *
 * Каждая операция (isAvailable/success/failure) берёт Redis-соединение из пула
 * и возвращает его обратно — RedisAdapter хранит ссылку на Redis-объект
 * и использует multi()/exec(), поэтому нельзя шарить соединение между корутинами.
 */
final class CircuitBreakerProxyFilter implements CircuitBreakerProxyFilterInterface
{
    use WithRedisConnectionTrait;

    public function __construct(
        private readonly ProxyRotationConfig $config,
        private readonly RedisConnectionPool $pool,
        private readonly ParseLogger $logger,
    ) {}

    public function isAvailable(string $proxyId): bool
    {
        try {
            return $this->withRedis(function (\Redis $redis) use ($proxyId): bool {
                return $this->makeBreaker($redis, $proxyId)->isAvailable();
            });
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[proxy] Ошибка проверки circuit breaker для %s: %s — считаем недоступным', $proxyId, $e->getMessage()),
                ['channel' => 'proxy'],
            );
            return false;
        }
    }

    public function recordSuccess(string $proxyId): void
    {
        try {
            $this->withRedis(function (\Redis $redis) use ($proxyId): void {
                $this->makeBreaker($redis, $proxyId)->success();
            });
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[proxy] Ошибка записи CB success для %s: %s', $proxyId, $e->getMessage()),
                ['channel' => 'proxy'],
            );
        }
    }

    public function recordFailure(string $proxyId): void
    {
        try {
            $this->withRedis(function (\Redis $redis) use ($proxyId): void {
                $this->makeBreaker($redis, $proxyId)->failure();
            });
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[proxy] Ошибка записи CB failure для %s: %s', $proxyId, $e->getMessage()),
                ['channel' => 'proxy'],
            );
        }
    }

    /**
     * Создаёт breaker с переданным Redis-соединением.
     *
     * Не кешируем — adapter держит ссылку на Redis и использует multi()/exec(),
     * поэтому соединение должно принадлежать одному вызову.
     */
    private function makeBreaker(\Redis $redis, string $proxyId): CircuitBreaker
    {
        $adapter = new RedisAdapter($redis, 'mp:proxy:cb');
        $breaker = new CircuitBreaker($adapter, 'proxy:' . $proxyId);
        $breaker->setSettings([
            'failureRateThreshold' => $this->config->circuitBreakerThreshold,
            'intervalToHalfOpen' => $this->config->circuitBreakerTimeoutSeconds,
            'timeWindow' => $this->config->circuitBreakerTimeoutSeconds,
        ]);

        return $breaker;
    }
}
