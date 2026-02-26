<?php

declare(strict_types=1);

namespace App\Module\Parser\Proxy;

use App\Module\Parser\Config\HttpConfig;
use App\Module\Parser\Proxy\Config\ProxyRotationConfig;
use App\Module\Parser\Queue\RedisConnectionPool;
use Psr\Log\LoggerInterface;

/**
 * Композитный ротатор прокси — фасад для цепочки декораторов.
 *
 * Цепочка: StickySession → HealthAware → RoundRobin
 * Дополнительно: CircuitBreaker проверяет доступность перед выбором.
 */
final class CompositeProxyRotator implements ProxyRotatorInterface
{
    private readonly ProxyRotatorInterface $chain;

    public function __construct(
        HttpConfig $httpConfig,
        RedisConnectionPool $redisPool,
        ProxyRotationConfig $rotationConfig,
        private readonly CircuitBreakerProxyFilterInterface $circuitBreaker,
        private readonly ProxyHealthTrackerInterface $healthTracker,
        private readonly LoggerInterface $logger,
        RoundRobinRotator $roundRobinRotator,
        private readonly ?ProxyProvider $proxyProvider = null,
    ) {
        $healthAware = new HealthAwareRotator($roundRobinRotator, $this->healthTracker, $httpConfig, $proxyProvider);
        $this->chain = new StickySessionRotator($healthAware, $rotationConfig, $httpConfig, $redisPool, $proxyProvider);
    }

    public function selectProxy(?string $stickyKey = null): ProxySelection
    {
        $selection = $this->chain->selectProxy($stickyKey);

        // Circuit breaker — проверяем выбранный прокси (для rotating не применяется)
        if (!$selection->isDirect() && $selection->type !== 'rotating' && !$this->circuitBreaker->isAvailable($selection->id)) {
            $this->logger->warning(
                sprintf('[proxy] Circuit breaker открыт для %s — fallback на direct', $selection->id),
                ['channel' => 'proxy'],
            );
            return new ProxySelection(address: null, id: 'direct', sessionKey: 'direct', source: 'direct');
        }

        // Health tracker — если chain уже вернул direct из-за нездоровых прокси, логируем
        if ($selection->isDirect() && $selection->source === 'health_fallback') {
            $this->logger->warning(
                '[proxy] Все прокси нездоровы — fallback на direct',
                ['channel' => 'proxy'],
            );
        }

        return $selection;
    }

    public function recordSuccess(string $proxyId): void
    {
        // Для rotating прокси не меняем health score и не дёргаем CB
        if ($this->isRotatingProxy($proxyId)) {
            $this->logger->debug(
                sprintf('[proxy] Пропуск health/CB для ротационной прокси %s (success)', $proxyId),
                ['channel' => 'proxy'],
            );
            return;
        }
        $this->healthTracker->recordSuccess($proxyId);
        $this->circuitBreaker->recordSuccess($proxyId);
    }

    public function recordFailure(string $proxyId): void
    {
        // Для rotating прокси не меняем health score и не дёргаем CB
        // Статистика solver_sessions записывается независимо
        if ($this->isRotatingProxy($proxyId)) {
            $this->logger->info(
                sprintf('[proxy] Пропуск health/CB для ротационной прокси %s (failure)', $proxyId),
                ['channel' => 'proxy'],
            );
            return;
        }
        $this->healthTracker->recordFailure($proxyId);
        $this->circuitBreaker->recordFailure($proxyId);
    }

    private function isRotatingProxy(string $proxyId): bool
    {
        return $this->proxyProvider !== null && $this->proxyProvider->isRotatingById($proxyId);
    }
}
