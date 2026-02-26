<?php

declare(strict_types=1);

namespace App\Module\Parser\Proxy;

use App\Module\Parser\Config\HttpConfig;
use App\Module\Parser\Config\RedisConfig;
use App\Shared\Infrastructure\RedisPoolInterface;

/**
 * Ротатор прокси по алгоритму round-robin через Redis INCR.
 *
 * Логика: INCR mp:proxy:rr:counter → counter % count(proxies) → индекс.
 * Fallback при недоступности Redis: array_rand() in-memory.
 */
class RoundRobinRotator implements ProxyRotatorInterface
{
    private readonly string $counterKey;

    public function __construct(
        private readonly HttpConfig $httpConfig,
        private readonly RedisPoolInterface $pool,
        private readonly RedisConfig $redisConfig,
        private readonly ?ProxyProvider $proxyProvider = null,
    ) {
        $this->counterKey = $redisConfig->prefix . 'proxy:rr:counter';
    }

    public function selectProxy(?string $stickyKey = null): ProxySelection
    {
        $allProxies = $this->proxyProvider !== null ? $this->proxyProvider->getAll() : [];
        $addresses = array_column($allProxies, 'address');

        if (!$this->httpConfig->proxyEnabled || (empty($this->httpConfig->proxies) && empty($addresses))) {
            return new ProxySelection(address: null, id: 'direct', sessionKey: 'direct', source: 'direct');
        }

        if (empty($allProxies)) {
            $allProxies = array_map(
                static fn(string $a): array => ['address' => $a, 'source' => 'env'],
                $this->httpConfig->proxies,
            );
            $addresses = $this->httpConfig->proxies;
        }

        $count = count($addresses);

        try {
            $counterKey = $this->counterKey;
            $index = $this->withPool(static function (mixed $redis) use ($counterKey, $count): int {
                $counter = (int) $redis->incr($counterKey);
                return $counter % $count;
            });
        } catch (\Throwable) {
            $index = (int) array_rand($addresses);
        }

        $selected = $allProxies[(int) $index];
        return new ProxySelection(
            address: $selected['address'],
            id: md5($selected['address']),
            sessionKey: $selected['address'],
            source: $selected['source'],
            type: $selected['type'] ?? 'static',
        );
    }

    public function recordSuccess(string $proxyId): void {}

    public function recordFailure(string $proxyId): void {}

    /**
     * Паттерн WithRedisConnectionTrait: get → try/catch → put.
     *
     * @template T
     * @param callable(mixed): T $operation
     * @return T
     */
    private function withPool(callable $operation): mixed
    {
        $redis = $this->pool->get();
        try {
            $result = $operation($redis);
            $this->pool->put($redis);
            return $result;
        } catch (\Throwable $e) {
            $this->pool->put($redis);
            throw $e;
        }
    }
}
