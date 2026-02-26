<?php

declare(strict_types=1);

namespace App\Module\Parser\Proxy\Config;

/**
 * Конфигурация для ротации прокси-серверов.
 *
 * Содержит параметры circuit breaker, health score decay и sticky-сессий.
 */
final readonly class ProxyRotationConfig
{
    public function __construct(
        /** Абсолютное число ошибок для срабатывания circuit breaker */
        public int $circuitBreakerThreshold = 3,
        /** Время в секундах в open state circuit breaker */
        public int $circuitBreakerTimeoutSeconds = 30,
        /** TTL в секундах для записей здоровья прокси */
        public int $healthScoreDecaySeconds = 60,
        /** TTL в секундах для sticky-привязки прокси к задаче */
        public int $stickySessionTtlSeconds = 300,
        /** Минимальный health score (0.0-1.0) для включения прокси в ротацию */
        public float $healthThreshold = 0.3,
    ) {}
}
