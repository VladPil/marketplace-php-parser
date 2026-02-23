<?php

declare(strict_types=1);

namespace App\Module\Parser\Config;

/**
 * Конфигурация воркеров парсера (Swoole-контекст).
 *
 * Все значения инжектируются из services.yaml / ENV.
 * Дефолты здесь не задаются — единый источник: services.yaml + parameters.
 */
final readonly class ParserConfig
{
    public function __construct(
        public int $rateLimit,
        public int $workers,
        public int $healthPort,
        public int $maxReviewPages,
    ) {}
}
