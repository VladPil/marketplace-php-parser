<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Monolog FormatterInterface: файловый формат без ANSI-цветов для админки (stderr, файлы).
 *
 * НЕ ДУБЛИРУЕТ Monolog — реализует его интерфейс FormatterInterface.
 * Monolog LineFormatter не умеет извлекать _channel/_trace/_task из context
 * и выводить в нужном формате. Это стандартная точка расширения.
 *
 * Формат: [2026-02-23 15:30:45] admin.INFO: Сообщение {"key":"value"}
 * Без ANSI-цветов — для файлов и Docker-логов.
 */
final class AdminFileFormatter implements FormatterInterface
{
    public function format(LogRecord $record): string
    {
        $time = $record->datetime->format('Y-m-d H:i:s');
        $level = $record->level->name;
        $channel = $record->context['_channel'] ?? $record->channel;

        // Убираем служебные поля
        $context = $record->context;
        unset($context['_channel'], $context['_trace'], $context['_task']);

        $line = sprintf('[%s] %s.%s: %s', $time, $channel, $level, $record->message);

        if (!empty($context)) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }

        if (!empty($record->extra)) {
            $line .= ' ' . json_encode($record->extra, JSON_UNESCAPED_UNICODE);
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
