<?php

declare(strict_types=1);

namespace App\Module\Parser\Config;

/**
 * Конфигурация PostgreSQL для парсера (Swoole-контекст).
 *
 * Все значения инжектируются из services.yaml / ENV.
 * Дефолты здесь не задаются — единый источник: services.yaml + parameters.
 */
final readonly class DatabaseConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $user,
        public string $password,
        public int $poolSize,
    ) {}

    public function getDsn(): string
    {
        return sprintf('pgsql:host=%s;port=%d;dbname=%s', $this->host, $this->port, $this->database);
    }
}
