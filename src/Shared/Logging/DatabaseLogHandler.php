<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use Doctrine\DBAL\Connection;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog handler: запись логов в таблицу parse_logs через Doctrine DBAL.
 *
 * НЕ ДУБЛИРУЕТ Monolog — это стандартная точка расширения (AbstractProcessingHandler).
 *
 * Используется в админке (FPM-контексте), где Doctrine доступна.
 * Для парсера (Swoole) запись в БД происходит через PgConnectionPool в ParseLogger —
 * два рантайма, разные средства работы с БД, отсюда два класса для одной задачи.
 */
final class DatabaseLogHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly Connection $connection,
        Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $context = $record->context;

        $channel = $context['_channel'] ?? $record->channel;
        $trace = $context['_trace'] ?? '';
        $task = $context['_task'] ?? null;
        $run = $context['_run'] ?? null;

        // Убираем служебные поля
        $dbContext = $context;
        unset($dbContext['_channel'], $dbContext['_trace'], $dbContext['_task'], $dbContext['_run']);

        try {
            $this->connection->insert('parse_logs', [
                'trace_id' => $trace !== '' ? $trace : bin2hex(random_bytes(6)),
                'parse_task_id' => $task,
                'run_id' => $run,
                'level' => strtolower($record->level->name),
                'channel' => $channel,
                'message' => $record->message,
                'context' => json_encode($dbContext, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable) {
            // БД недоступна — не блокируем приложение
        }
    }
}
