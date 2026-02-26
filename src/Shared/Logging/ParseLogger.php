<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use App\Module\Parser\Storage\PgConnectionPool;
use App\Shared\Tracing\TraceContext;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Логгер парсинга: форматированный вывод в stdout + запись в БД.
 *
 * Обходит Monolog Logger полностью — вызывает ParseLineFormatter
 * напрямую и пишет в stdout через echo. Это необходимо потому что
 * Monolog\Logger::addRecord() использует $logDepth счётчик,
 * который не coroutine-safe: Swoole fwrite hook вызывает context switch
 * внутри addRecord(), другая корутина тоже входит в addRecord(),
 * Monolog видит logDepth > 0 и абортит с "infinite logging loop".
 */
final class ParseLogger implements LoggerInterface
{
    private const PSR_TO_MONOLOG = [
        LogLevel::EMERGENCY => Level::Emergency,
        LogLevel::ALERT => Level::Alert,
        LogLevel::CRITICAL => Level::Critical,
        LogLevel::ERROR => Level::Error,
        LogLevel::WARNING => Level::Warning,
        LogLevel::NOTICE => Level::Notice,
        LogLevel::INFO => Level::Info,
        LogLevel::DEBUG => Level::Debug,
    ];

    public function __construct(
        private readonly ParseLineFormatter $formatter,
        private readonly PgConnectionPool $pool,
    ) {
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

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $channel = $context['channel'] ?? 'parser';
        $traceId = TraceContext::getTraceId();
        $taskId = TraceContext::getTaskId();
        $runId = TraceContext::getRunId();

        $displayContext = $context;
        unset($displayContext['channel']);
        $displayContext['_channel'] = $channel;
        if ($traceId !== '') {
            $displayContext['_trace'] = substr($traceId, 0, 12);
        }
        if ($taskId !== null) {
            $displayContext['_task'] = substr($taskId, 0, 8);
        }
        if ($runId !== null) {
            $displayContext['_run'] = substr($runId, 0, 8);
        }

        $monologLevel = self::PSR_TO_MONOLOG[$level] ?? Level::Info;
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'parser',
            level: $monologLevel,
            message: (string) $message,
            context: $displayContext,
        );

        echo $this->formatter->format($record);

        $dbContext = $context;
        unset($dbContext['channel']);

        try {
            $pdo = $this->pool->get();
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO parse_logs (trace_id, parse_task_id, run_id, level, channel, message, context)
                     VALUES (:trace_id, :task_id, :run_id, :level, :channel, :message, :context)',
                );
                $stmt->execute([
                    'trace_id' => $traceId,
                    'task_id' => $taskId,
                    'run_id' => $runId,
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
            // БД недоступна — лог уже в stdout
        }
    }
}
