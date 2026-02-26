<?php

declare(strict_types=1);

namespace App\Shared\Tracing;

use App\Module\Parser\Identity\Identity;
use Swoole\Coroutine;

/**
 * Контекст трассировки для сквозного отслеживания запросов.
 *
 * Хранит trace_id текущей операции и опциональную привязку к задаче парсинга.
 * Используется всеми уровнями приложения (воркеры, логгер, API-клиент)
 * для единой идентификации цепочки вызовов.
 *
 * При работе внутри Swoole-корутины данные хранятся в per-coroutine контексте
 * (Coroutine::getContext()), что обеспечивает изоляцию между воркерами.
 * Вне корутины — fallback на статические свойства (для admin/CLI).
 */
final class TraceContext
{
    private const KEY_TRACE_ID = '_trace_id';
    private const KEY_TASK_ID = '_task_id';
    private const KEY_IDENTITY = '_identity';
    private const KEY_RUN_ID = '_run_id';

    // Fallback для работы вне корутин (admin, CLI)
    private static ?string $traceId = null;
    private static ?string $taskId = null;
    private static ?Identity $identity = null;
    private static ?string $runId = null;

    /**
     * Проверяет, работаем ли мы внутри Swoole-корутины.
     */
    private static function inCoroutine(): bool
    {
        return class_exists(Coroutine::class) && Coroutine::getCid() > 0;
    }

    /**
     * Генерирует новый trace_id и устанавливает его как текущий.
     *
     * @return string Сгенерированный trace_id (32 символа hex)
     */
    public static function generate(): string
    {
        $traceId = bin2hex(random_bytes(16));
        self::set(self::KEY_TRACE_ID, $traceId);
        return $traceId;
    }

    /**
     * Возвращает текущий trace_id или генерирует новый при отсутствии.
     */
    public static function getTraceId(): string
    {
        $traceId = self::get(self::KEY_TRACE_ID);
        if ($traceId === null) {
            return self::generate();
        }
        return $traceId;
    }

    /**
     * Устанавливает trace_id (например, полученный от solver-service).
     */
    public static function setTraceId(string $traceId): void
    {
        self::set(self::KEY_TRACE_ID, $traceId);
    }

    /**
     * Возвращает идентификатор текущей задачи парсинга.
     */
    public static function getTaskId(): ?string
    {
        return self::get(self::KEY_TASK_ID);
    }

    /**
     * Привязывает контекст к конкретной задаче парсинга.
     */
    public static function setTaskId(?string $taskId): void
    {
        self::set(self::KEY_TASK_ID, $taskId);
    }

    public static function getIdentity(): ?Identity
    {
        return self::get(self::KEY_IDENTITY);
    }

    public static function setIdentity(?Identity $identity): void
    {
        self::set(self::KEY_IDENTITY, $identity);
    }

    /**
     * Возвращает идентификатор текущего запуска задачи.
     */
    public static function getRunId(): ?string
    {
        return self::get(self::KEY_RUN_ID);
    }

    /**
     * Привязывает контекст к конкретному запуску задачи.
     */
    public static function setRunId(?string $runId): void
    {
        self::set(self::KEY_RUN_ID, $runId);
    }

    /**
     * Сбрасывает контекст трассировки (при завершении задачи).
     */
    public static function reset(): void
    {
        self::set(self::KEY_TRACE_ID, null);
        self::set(self::KEY_TASK_ID, null);
        self::set(self::KEY_RUN_ID, null);
        self::set(self::KEY_IDENTITY, null);
    }
    /**
     * Копирует контекст текущей корутины в дочернюю.
     *
     * Вызывается в sub-coroutine (executeWithTimeout) чтобы
     * дочерняя корутина унаследовала trace_id, taskId и identity.
     */
    public static function inheritFromParent(): void
    {
        if (!self::inCoroutine()) {
            return;
        }

        $parentCid = Coroutine::getPcid();
        if ($parentCid <= 0) {
            return;
        }

        $parentCtx = Coroutine::getContext($parentCid);
        if ($parentCtx === null) {
            return;
        }

        $ctx = Coroutine::getContext();
        foreach ([self::KEY_TRACE_ID, self::KEY_TASK_ID, self::KEY_RUN_ID, self::KEY_IDENTITY] as $key) {
            if (isset($parentCtx[$key])) {
                $ctx[$key] = $parentCtx[$key];
            }
        }
    }

    /**
     * Записывает значение в контекст корутины или static-fallback.
     */
    private static function set(string $key, mixed $value): void
    {
        if (self::inCoroutine()) {
            $ctx = Coroutine::getContext();
            $ctx[$key] = $value;
            return;
        }

        match ($key) {
            self::KEY_TRACE_ID => self::$traceId = $value,
            self::KEY_TASK_ID => self::$taskId = $value,
            self::KEY_RUN_ID => self::$runId = $value,
            self::KEY_IDENTITY => self::$identity = $value,
        };
    }

    /**
     * Читает значение из контекста корутины или static-fallback.
     */
    private static function get(string $key): mixed
    {
        if (self::inCoroutine()) {
            $ctx = Coroutine::getContext();
            return $ctx[$key] ?? null;
        }

        return match ($key) {
            self::KEY_TRACE_ID => self::$traceId,
            self::KEY_TASK_ID => self::$taskId,
            self::KEY_RUN_ID => self::$runId,
            self::KEY_IDENTITY => self::$identity,
            default => null,
        };
    }
}
