<?php

declare(strict_types=1);

namespace App\Module\Admin\Service;

use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * Сервис статистики пула identity для админ-панели.
 *
 * Читает напрямую из Redis ключи mp:identity:* для отображения
 * количества прогретых/активных/карантинных identity в sidebar и на странице здоровья.
 *
 * Работает в FPM-контейнере (админка), поэтому использует Symfony RedisAdapter,
 * а не Swoole RedisConnectionPool.
 */
final class IdentityPoolStats
{
    private const KEY_READY = 'mp:identity:ready';
    private const KEY_ACTIVE = 'mp:identity:active';
    private const KEY_QUARANTINE = 'mp:identity:quarantine';
    private const KEY_DATA_PREFIX = 'mp:identity:data:';

    private \Redis $redis;

    public function __construct(
        string $redisDsn = 'redis://redis:6379',
    ) {
        $this->redis = RedisAdapter::createConnection($redisDsn);
    }

    /**
     * Возвращает полную статистику пула identity.
     *
     * @return array{
     *     ready: int,
     *     active: int,
     *     quarantine: int,
     *     total: int,
     *     identities: list<array{
     *         id: string,
     *         short_id: string,
     *         proxy: string,
     *         proxy_host: string,
     *         proxy_type: string,
     *         status: string,
     *         cookies: int,
     *         created_at: string|null,
     *         claimed_by: string|null,
     *     }>,
     * }
     */
    public function getStats(): array
    {
        try {
            $readyCount = (int) $this->redis->lLen(self::KEY_READY);
            $activeCount = (int) $this->redis->sCard(self::KEY_ACTIVE);
            $quarantineCount = (int) $this->redis->lLen(self::KEY_QUARANTINE);


            $allIds = array_unique(array_merge(
                $this->redis->lRange(self::KEY_READY, 0, -1) ?: [],
                $this->redis->sMembers(self::KEY_ACTIVE) ?: [],
                $this->redis->lRange(self::KEY_QUARANTINE, 0, -1) ?: [],
            ));

            $identities = [];
            if (!empty($allIds)) {
                $keys = array_map(fn(string $id) => self::KEY_DATA_PREFIX . $id, $allIds);
                $values = $this->redis->mGet($keys) ?: [];

                foreach ($values as $json) {
                    if ($json === false || !is_string($json)) {
                        continue;
                    }

                    $data = json_decode($json, true);
                    if (!is_array($data)) {
                        continue;
                    }

                    $proxy = $data['proxy_address'] ?? null;
                    $maskedProxy = $proxy !== null ? $this->maskProxy($proxy) : 'direct';
                    $proxyHost = $proxy !== null ? $this->extractProxyHost($proxy) : 'direct';

                    $cookies = 0;
                    if (isset($data['session']['cookies']) && is_array($data['session']['cookies'])) {
                        $cookies = count($data['session']['cookies']);
                    }

                    $createdAt = isset($data['created_at'])
                        ? date('Y-m-d H:i:s', (int) $data['created_at'])
                        : null;

                    $ttlSeconds = 1200;
                    $ttlRemaining = null;
                    $warmthPct = null;
                    $expiresAt = null;
                    if (isset($data['created_at'])) {
                        $age = time() - (int) $data['created_at'];
                        $ttlRemaining = max(0, $ttlSeconds - $age);
                        $warmthPct = (int) round(($ttlRemaining / $ttlSeconds) * 100);
                        $expiresAt = date('H:i:s', (int) $data['created_at'] + $ttlSeconds);
                    }

                    $identities[] = [
                        'id' => $data['id'] ?? 'unknown',
                        'short_id' => substr($data['id'] ?? 'unknown', 0, 8),
                        'proxy' => $maskedProxy,
                        'proxy_host' => $proxyHost,
                        'proxy_type' => $data['proxy_type'] ?? 'static',
                        'status' => $data['status'] ?? 'unknown',
                        'cookies' => $cookies,
                        'created_at' => $createdAt,
                        'claimed_by' => $data['claimed_by'] ?? null,
                        'ttl_remaining' => $ttlRemaining,
                        'warmth_pct' => $warmthPct,
                        'expires_at' => $expiresAt,
                    ];
                }
            }

            return [
                'ready' => $readyCount,
                'active' => $activeCount,
                'quarantine' => $quarantineCount,
                'total' => $readyCount + $activeCount + $quarantineCount,
                'identities' => $identities,
            ];
        } catch (\Throwable) {
            return [
                'ready' => 0,
                'active' => 0,
                'quarantine' => 0,
                'total' => 0,
                'identities' => [],
            ];
        }
    }

    public function getWarmerStatus(): array
    {
        try {
            $json = $this->redis->get('mp:identity:warmer:status');
            if ($json === false || !is_string($json)) {
                return ['available' => false];
            }
            $data = json_decode($json, true);
            if (!is_array($data)) {
                return ['available' => false];
            }
            $data['available'] = true;
            if (isset($data['last_run_at'])) {
                $data['last_run_ago'] = (int) (microtime(true) - $data['last_run_at']);
                $data['last_run_formatted'] = date('H:i:s', (int) $data['last_run_at']);
            }
            return $data;
        } catch (\Throwable) {
            return ['available' => false];
        }
    }

    private function maskProxy(string $proxy): string
    {
        $parts = explode('@', $proxy);

        return count($parts) > 1 ? '***@' . end($parts) : $proxy;
    }

    /**
     * Извлекает host:port из URL прокси для сопоставления с проксями в админке.
     */
    private function extractProxyHost(string $proxy): string
    {
        $parsed = parse_url($proxy);
        if ($parsed === false || !isset($parsed['host'])) {
            // Формат host:port@user:pass (legacy)
            $parts = explode('@', $proxy);
            return $parts[0];
        }

        $host = $parsed['host'];
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        return $host . $port;
    }
}
