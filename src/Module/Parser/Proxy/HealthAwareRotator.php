<?php

declare(strict_types=1);

namespace App\Module\Parser\Proxy;

use App\Module\Parser\Config\HttpConfig;

/**
 * Декоратор: фильтрует нездоровые прокси перед выбором.
 *
 * Если все прокси нездоровы — fallback на direct connection.
 */
final class HealthAwareRotator implements ProxyRotatorInterface
{
    public function __construct(
        private readonly ProxyRotatorInterface $inner,
        private readonly ProxyHealthTrackerInterface $healthTracker,
        private readonly HttpConfig $httpConfig,
        private readonly ?ProxyProvider $proxyProvider = null,
    ) {}

    public function selectProxy(?string $stickyKey = null): ProxySelection
    {
        $selection = $this->inner->selectProxy($stickyKey);

        // Direct — пропускаем проверку здоровья
        if ($selection->isDirect()) {
            return $selection;
        }

        // Проверяем здоровье выбранного прокси
        if ($this->healthTracker->isHealthy($selection->id)) {
            return $selection;
        }

        $allProxies = $this->proxyProvider !== null ? $this->proxyProvider->getAll() : [];
        if (empty($allProxies)) {
            $allProxies = array_map(
                static fn(string $a): array => ['address' => $a, 'source' => 'env'],
                $this->httpConfig->proxies,
            );
        }

        $allProxyIds = array_map(static fn(array $p): string => md5($p['address']), $allProxies);
        $healthyIds = $this->healthTracker->filterHealthy($allProxyIds);

        if (empty($healthyIds)) {
            return new ProxySelection(address: null, id: 'direct', sessionKey: 'direct', source: 'health_fallback');
        }
        $healthyId = $healthyIds[0];
        $proxyMap = [];
        foreach ($allProxies as $item) {
            $proxyMap[md5($item['address'])] = $item;
        }

        $found = $proxyMap[$healthyId];
        return new ProxySelection(
            address: $found['address'],
            id: $healthyId,
            sessionKey: $found['address'],
            source: $found['source'],
            type: $found['type'] ?? 'static',
        );
    }

    public function recordSuccess(string $proxyId): void
    {
        $this->healthTracker->recordSuccess($proxyId);
        $this->inner->recordSuccess($proxyId);
    }

    public function recordFailure(string $proxyId): void
    {
        $this->healthTracker->recordFailure($proxyId);
        $this->inner->recordFailure($proxyId);
    }
}
