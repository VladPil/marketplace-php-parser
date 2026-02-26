<?php

declare(strict_types=1);

namespace App\Module\Parser\Proxy;

use App\Module\Parser\Config\HttpConfig;
use App\Module\Parser\Proxy\Config\ProxyRotationConfig;
use App\Module\Parser\Queue\RedisConnectionPool;
use App\Shared\Infrastructure\WithRedisConnectionTrait;

/**
 * Декоратор: привязка прокси к задаче (sticky session).
 *
 * Все запросы одной задачи идут через один прокси.
 * При отсутствии stickyKey — делегирует inner напрямую.
 */
final class StickySessionRotator implements ProxyRotatorInterface
{
    use WithRedisConnectionTrait;

    private const REDIS_PREFIX = 'mp:proxy:sticky:';

    public function __construct(
        private readonly ProxyRotatorInterface $inner,
        private readonly ProxyRotationConfig $config,
        private readonly HttpConfig $httpConfig,
        private readonly RedisConnectionPool $pool,
        private readonly ?ProxyProvider $proxyProvider = null,
    ) {}

    public function selectProxy(?string $stickyKey = null): ProxySelection
    {
        if ($stickyKey === null) {
            return $this->inner->selectProxy(null);
        }

        // Пробуем достать sticky-привязку из Redis
        $cachedProxyId = $this->getCachedProxy($stickyKey);
        if ($cachedProxyId !== null) {
            $selection = $this->resolveProxyById($cachedProxyId);
            if ($selection !== null) {
                return $selection;
            }
        }

        // Нет привязки — выбираем через inner и кешируем
        $selection = $this->inner->selectProxy($stickyKey);
        $this->cacheProxy($stickyKey, $selection->id);

        return $selection;
    }

    public function recordSuccess(string $proxyId): void
    {
        $this->inner->recordSuccess($proxyId);
    }

    public function recordFailure(string $proxyId): void
    {
        $this->inner->recordFailure($proxyId);
    }

    private function getCachedProxy(string $stickyKey): ?string
    {
        try {
            return $this->withRedis(static function (\Redis $redis) use ($stickyKey): ?string {
                $value = $redis->get(self::REDIS_PREFIX . $stickyKey);
                return $value === false ? null : (string) $value;
            });
        } catch (\Throwable) {
            return null;
        }
    }

    private function cacheProxy(string $stickyKey, string $proxyId): void
    {
        try {
            $ttl = $this->config->stickySessionTtlSeconds;
            $this->withRedis(static function (\Redis $redis) use ($stickyKey, $proxyId, $ttl): void {
                $redis->setex(self::REDIS_PREFIX . $stickyKey, $ttl, $proxyId);
            });
        } catch (\Throwable) {
            // Redis недоступен — игнорируем
        }
    }

    private function resolveProxyById(string $proxyId): ?ProxySelection
    {
        if ($proxyId === 'direct') {
            return new ProxySelection(address: null, id: 'direct', sessionKey: 'direct', source: 'direct');
        }

        $allProxies = $this->proxyProvider !== null ? $this->proxyProvider->getAll() : [];
        if (empty($allProxies)) {
            $allProxies = array_map(
                static fn(string $a): array => ['address' => $a, 'source' => 'env'],
                $this->httpConfig->proxies,
            );
        }

        foreach ($allProxies as $item) {
            if (md5($item['address']) === $proxyId) {
                return new ProxySelection(
                    address: $item['address'],
                    id: $proxyId,
                    sessionKey: $item['address'],
                    source: $item['source'],
                    type: $item['type'] ?? 'static',
                );
            }
        }

        return null;
    }
}
