<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use App\Module\Parser\Storage\PgConnectionPool;
use App\Shared\Tracing\TraceContext;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Логгер парсинга: Monolog для stdout + запись в БД.
 *
 * НЕ ДУБЛИРУЕТ Monolog — это декоратор поверх него.
 *
 * Почему нужен отдельный класс, а не просто Monolog handler:
 * - В Swoole-контексте нет Doctrine DBAL, поэтому запись в БД идёт
 *   через PgConnectionPool (Swoole-совместимый пул PDO-соединений).
 * - DatabaseLogHandler решает ту же задачу (запись в parse_logs),
 *   но для FPM/админки, где Doctrine доступна.
 * - Этот класс обогащает context метаданными (trace_id, task_id)
 *   из TraceContext до передачи в Monolog.
 *
 * Все логи выводятся через Monolog (красивый формат с цветами и метаданными),
 * а также сохраняются в таблицу parse_logs с привязкой к trace_id/task_id.
 */
final class ParseLogger implements LoggerInterface
{
    private LoggerInterface $monolog;
    private PgConnectionPool $pool;

    public function __construct(
        LoggerInterface $monolog,
        PgConnectionPool $pool,
    ) {
        $this->monolog = $monolog;
        $this->pool = $pool;
    }

    public function emergency(\Stringable|string $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(\Stringable|string $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(\Stringable|string $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(\Stringable|string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(\Stringable|string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(\Stringable|string $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(\Stringable|string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(\Stringable|string $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * Записывает лог через Monolog + в БД.
     *
     * Канал передаётся через context['channel'] (по умолчанию 'parser').
     * trace_id и task_id берутся из TraceContext.
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $channel = $context['channel'] ?? 'parser';
        $traceId = TraceContext::getTraceId();
        $taskId = TraceContext::getTaskId();

        // Добавляем метаданные в context для Monolog
        $monologContext = $context;
        unset($monologContext['channel']); // channel — не часть данных, а метаинформация
        $monologContext['_channel'] = $channel;
        if ($traceId !== '') {
            $monologContext['_trace'] = substr($traceId, 0, 12);
        }
        if ($taskId !== null) {
            $monologContext['_task'] = substr($taskId, 0, 8);
        }

        // Вывод через Monolog (stdout)
        $this->monolog->log($level, (string) $message, $monologContext);

        // Запись в БД
        $dbContext = $context;
        unset($dbContext['channel']);

        try {
            $pdo = $this->pool->get();
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO parse_logs (trace_id, parse_task_id, level, channel, message, context)
                     VALUES (:trace_id, :task_id, :level, :channel, :message, :context)',
                );
                $stmt->execute([
                    'trace_id' => $traceId,
                    'task_id' => $taskId,
                    'level' => $level,
                    'channel' => $channel,
                    'message' => (string) $message,
                    'context' => json_encode($dbContext, JSON_UNESCAPED_UNICODE),
                ]);
                $this->pool->put($pdo);
            } catch (\Throwable) {
                $this->pool->put($pdo);
            }
        } catch (\Throwable) {
            // БД недоступна — логи уже в stdout через Monolog
        }
    }
}
