<?php

declare(strict_types=1);

namespace App\Module\Admin\Service;

use App\Shared\Repository\SolverSessionRepository;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * Сервис статистики прокси — собирает данные из Redis и БД
 * для отображения на странице управления прокси.
 *
 * Источники данных:
 * - Redis ZSET `mp:proxy:health` — health-скоры прокси (0.0—1.0)
 * - Redis keys `circuit-breaker:mp:proxy:cb:proxy:{id}:*` — состояние circuit breaker
 * - Redis keys `mp:proxy:sticky:*` — sticky-привязки прокси к задачам
 * - Таблица `solver_sessions` — агрегация по прокси (total/success/error)
 */
final class ProxyStatsService
{
    private \Redis $redis;

    public function __construct(
        private readonly SolverSessionRepository $solverSessionRepository,
        string $redisDsn = 'redis://redis:6379',
    ) {
        $this->redis = RedisAdapter::createConnection($redisDsn);
    }

    /**
     * Собирает полную статистику для всех прокси.
     *
     * @param list<array{address: string, source: string, type?: string}> $allProxies Все прокси (env + admin)
     * @return array{
     *     perProxy: array<string, array{
     *         health: float,
     *         cbState: string,
     *         cbFailures: int,
     *         stickySessions: int,
     *         solverTotal: int,
     *         solverSuccess: int,
     *         solverError: int,
     *         lastUsed: string|null,
     *     }>,
     *     summary: array{
     *         total: int,
     *         healthy: int,
     *         cbOpen: int,
     *         stickyTotal: int,
     *     },
     * }
     */
    public function collectStats(array $allProxies): array
    {
        $healthScores = $this->getAllHealthScores();
        $stickyByProxy = $this->getStickySessionsByProxy();
        $solverStats = $this->solverSessionRepository->getStatsByProxy();

        $perProxy = [];
        $summary = [
            'total' => count($allProxies),
            'healthy' => 0,
            'cbOpen' => 0,
            'stickyTotal' => 0,
        ];

        foreach ($allProxies as $proxy) {
            $proxyId = md5($proxy['address']);
            $health = $healthScores[$proxyId] ?? 1.0;
            $cb = $this->getCircuitBreakerState($proxyId);
            $sticky = $stickyByProxy[$proxyId] ?? 0;
            $solver = $solverStats[$proxy['address']] ?? [
                'total' => 0, 'success' => 0, 'error' => 0, 'lastUsed' => null,
            ];

            $proxyType = $proxy['type'] ?? 'static';
            $perProxy[$proxyId] = [
                'type' => $proxyType,
                'health' => round((float) $health, 2),
                'cbState' => $cb['state'],
                'cbFailures' => $cb['failures'],
                'stickySessions' => $sticky,
                'solverTotal' => $solver['total'],
                'solverSuccess' => $solver['success'],
                'solverError' => $solver['error'],
                'lastUsed' => $solver['lastUsed'],
            ];

            // Порог здоровья 0.3 (из ProxyRotationConfig)
            if ($health >= 0.3) {
                $summary['healthy']++;
            }
            if ($cb['state'] === 'open') {
                $summary['cbOpen']++;
            }
            $summary['stickyTotal'] += $sticky;
        }

        return ['perProxy' => $perProxy, 'summary' => $summary];
    }

    /**
     * Читает все health-скоры из Redis ZSET.
     *
     * @return array<string, float> proxyId => score
     */
    private function getAllHealthScores(): array
    {
        try {
            /** @var array<string, float>|false $scores */
            $scores = $this->redis->zRange('mp:proxy:health', 0, -1, true);

            return is_array($scores) ? $scores : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Определяет состояние circuit breaker для прокси.
     *
     * Ключи библиотеки leocarmo/circuit-breaker-php:
     * - circuit-breaker:{namespace}:{service}:open
     * - circuit-breaker:{namespace}:{service}:half_open
     * - circuit-breaker:{namespace}:{service}:failures
     *
     * Наша конфигурация: namespace=mp:proxy:cb, service=proxy:{proxyId}
     *
     * @return array{state: string, failures: int}
     */
    private function getCircuitBreakerState(string $proxyId): array
    {
        try {
            $prefix = 'circuit-breaker:mp:proxy:cb:proxy:' . $proxyId;

            $isOpen = (bool) $this->redis->get($prefix . ':open');
            $isHalfOpen = (bool) $this->redis->get($prefix . ':half_open');
            $failures = (int) ($this->redis->get($prefix . ':failures') ?: 0);

            $state = 'closed';
            if ($isOpen) {
                $state = 'open';
            } elseif ($isHalfOpen) {
                $state = 'half_open';
            }

            return ['state' => $state, 'failures' => $failures];
        } catch (\Throwable) {
            return ['state' => 'unknown', 'failures' => 0];
        }
    }

    /**
     * Считает количество sticky-сессий для каждого прокси.
     *
     * Сканирует Redis-ключи mp:proxy:sticky:* и группирует по proxyId.
     *
     * @return array<string, int> proxyId => количество привязок
     */
    private function getStickySessionsByProxy(): array
    {
        try {
            $result = [];
            $iterator = null;

            do {
                /** @var string[]|false $keys */
                $keys = $this->redis->scan($iterator, 'mp:proxy:sticky:*', 100);

                if ($keys !== false && $keys !== []) {
                    /** @var string[]|false $values */
                    $values = $this->redis->mGet($keys);

                    if (is_array($values)) {
                        foreach ($values as $proxyId) {
                            if ($proxyId !== false && is_string($proxyId)) {
                                $result[$proxyId] = ($result[$proxyId] ?? 0) + 1;
                            }
                        }
                    }
                }
            } while ($iterator > 0);

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }
}
