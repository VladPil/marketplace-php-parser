<?php

declare(strict_types=1);

namespace App\Shared\Tracing;

/**
 * Контекст трассировки для сквозного отслеживания запросов.
 *
 * Хранит trace_id текущей операции и опциональную привязку к задаче парсинга.
 * Используется всеми уровнями приложения (воркеры, логгер, API-клиент)
 * для единой идентификации цепочки вызовов.
 */
final class TraceContext
{
    private static ?string $traceId = null;
    private static ?string $taskId = null;

    /**
     * Генерирует новый trace_id и устанавливает его как текущий.
     *
     * @return string Сгенерированный trace_id (32 символа hex)
     */
    public static function generate(): string
    {
        self::$traceId = bin2hex(random_bytes(16));
        return self::$traceId;
    }

    /**
     * Возвращает текущий trace_id или генерирует новый при отсутствии.
     */
    public static function getTraceId(): string
    {
        if (self::$traceId === null) {
            self::generate();
        }
        return self::$traceId;
    }

    /**
     * Устанавливает trace_id (например, полученный от solver-service).
     */
    public static function setTraceId(string $traceId): void
    {
        self::$traceId = $traceId;
    }

    /**
     * Возвращает идентификатор текущей задачи парсинга.
     */
    public static function getTaskId(): ?string
    {
        return self::$taskId;
    }

    /**
     * Привязывает контекст к конкретной задаче парсинга.
     */
    public static function setTaskId(?string $taskId): void
    {
        self::$taskId = $taskId;
    }

    /**
     * Сбрасывает контекст трассировки (при завершении задачи).
     */
    public static function reset(): void
    {
        self::$traceId = null;
        self::$taskId = null;
    }
}
