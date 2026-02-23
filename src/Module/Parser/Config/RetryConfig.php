<?php

declare(strict_types=1);

namespace App\Module\Parser\Config;

/**
 * Конфигурация повторных попыток HTTP-запросов.
 *
 * Все значения инжектируются из services.yaml / ENV.
 * Дефолты здесь не задаются — единый источник: services.yaml + parameters.
 */
final readonly class RetryConfig
{
    public function __construct(
        public int $maxRetries,
        public float $baseDelaySeconds,
        public float $maxDelaySeconds,
        public float $jitterFactor,
    ) {}
}
