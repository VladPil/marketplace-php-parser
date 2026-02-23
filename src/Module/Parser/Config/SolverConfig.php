<?php

declare(strict_types=1);

namespace App\Module\Parser\Config;

/**
 * Конфигурация подключения к solver-service.
 *
 * Содержит параметры HTTP-клиента для запросов к Python-сервису
 * обхода анти-бот защиты и настройки кеширования сессий.
 */
final readonly class SolverConfig
{
    /**
     * @param string $host Хост solver-service
     * @param int $port Порт solver-service
     * @param int $requestTimeoutSeconds Таймаут запроса к solver (секунды)
     * @param int $connectionTimeoutSeconds Таймаут подключения к solver (секунды)
     * @param int $sessionTtlSeconds TTL кеша сессии в Redis (секунды)
     * @param int $maxSolveRetries Максимум повторных попыток запроса к solver
     */
    public function __construct(
        public string $host,
        public int $port,
        public int $requestTimeoutSeconds,
        public int $connectionTimeoutSeconds,
        public int $sessionTtlSeconds,
        public int $maxSolveRetries,
    ) {}
}
