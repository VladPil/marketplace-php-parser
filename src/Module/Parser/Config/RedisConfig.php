<?php

declare(strict_types=1);

namespace App\Module\Parser\Config;

/**
 * Конфигурация Redis для парсера (Swoole-контекст).
 *
 * Все значения инжектируются из services.yaml / ENV.
 * Дефолты здесь не задаются — единый источник: services.yaml + parameters.
 */
final readonly class RedisConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public int $database,
        public int $poolSize,
        public string $prefix,
    ) {}
}
