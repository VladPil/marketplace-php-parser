<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Monolog FormatterInterface: консольный формат с ANSI-цветами для парсера (stdout).
 *
 * НЕ ДУБЛИРУЕТ Monolog — реализует его интерфейс FormatterInterface,
 * стандартный способ кастомизации вывода. Monolog не предоставляет
 * встроенного форматтера с trace_id/task_id и цветовой раскраской по уровням.
 *
 * Формат: 2026-02-23 15:30:45 INFO    [parser] [abc123] [task:def456] Сообщение
 *             {context}
 *
 * Цвета: DEBUG=серый, INFO=зелёный, WARNING=жёлтый, ERROR/CRITICAL=красный.
 */
final class ParseLineFormatter implements FormatterInterface
{
    /** ANSI-коды для уровней логирования */
    private const LEVEL_COLORS = [
        'DEBUG' => "\033[90m",      // серый
        'INFO' => "\033[32m",       // зелёный
        'NOTICE' => "\033[36m",     // голубой
        'WARNING' => "\033[33m",    // жёлтый
        'ERROR' => "\033[31m",      // красный
        'CRITICAL' => "\033[1;31m", // жирный красный
        'ALERT' => "\033[1;31m",    // жирный красный
        'EMERGENCY' => "\033[1;31m",// жирный красный
    ];

    private const RESET = "\033[0m";
    private const DIM = "\033[90m";
    private const CYAN = "\033[36m";
    private const MAGENTA = "\033[35m";

    public function format(LogRecord $record): string
    {
        $level = strtoupper($record->level->name);
        $color = self::LEVEL_COLORS[$level];
        $context = $record->context;

        // Извлекаем метаданные
        $channel = $context['_channel'] ?? 'app';
        $trace = $context['_trace'] ?? null;
        $task = $context['_task'] ?? null;

        // Убираем служебные поля из вывода контекста
        $displayContext = $context;
        unset($displayContext['_channel'], $displayContext['_trace'], $displayContext['_task']);

        // Временная метка
        $time = $record->datetime->format('Y-m-d H:i:s');

        // Уровень (фиксированная ширина 7 символов для выравнивания)
        $levelPadded = str_pad($level, 7);

        // Собираем строку
        $line = sprintf(
            "%s%s%s %s%-7s%s %s[%s]%s",
            self::DIM,
            $time,
            self::RESET,
            $color,
            $levelPadded,
            self::RESET,
            self::CYAN,
            $channel,
            self::RESET,
        );

        // Trace/Task
        if ($trace !== null) {
            $line .= sprintf(" %s[%s]%s", self::DIM, $trace, self::RESET);
        }
        if ($task !== null) {
            $line .= sprintf(" %s[task:%s]%s", self::MAGENTA, $task, self::RESET);
        }

        // Сообщение
        $line .= ' ' . $record->message;

        // Контекст (если не пустой)
        if (!empty($displayContext)) {
            $json = json_encode($displayContext, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            // Отступ для многострочного JSON
            $indented = str_replace("\n", "\n    ", $json);
            $line .= sprintf("\n    %s%s%s", self::DIM, $indented, self::RESET);
        }

        return $line . "\n";
    }

    public function formatBatch(array $records): string
    {
        $output = '';
        foreach ($records as $record) {
            $output .= $this->format($record);
        }
        return $output;
    }
}
