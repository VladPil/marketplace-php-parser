<?php

declare(strict_types=1);

namespace App\Module\Parser\Proxy;

/**
 * Контракт трекера здоровья прокси.
 */
interface ProxyHealthTrackerInterface
{
    public function recordSuccess(string $proxyId): void;

    public function recordFailure(string $proxyId): void;

    /**
     * Возвращает health score для прокси (0.0-1.0).
     */
    public function getHealthScore(string $proxyId): float;

    /**
     * Проверяет, считается ли прокси здоровым.
     */
    public function isHealthy(string $proxyId): bool;

    /**
     * Возвращает здоровые прокси из переданного списка.
     *
     * @param string[] $proxyIds
     * @return string[]
     */
    public function filterHealthy(array $proxyIds): array;
}
