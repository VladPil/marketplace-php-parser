<?php

declare(strict_types=1);

namespace App\Module\Admin\Service\FileParser;

/**
 * Парсер TXT-файлов.
 *
 * Одно значение на строку. Автоматически пропускает заголовок.
 */
final class TxtFileParser implements FileParserInterface
{
    public function __construct(
        private readonly ValueRecognizer $recognizer,
    ) {}

    public function supports(string $extension): bool
    {
        return $extension === 'txt';
    }

    public function parse(string $filePath): array
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            return [];
        }

        // Удаляем BOM
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        // Конвертируем кодировку при необходимости
        $encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1251', 'ISO-8859-1'], true);
        if ($encoding !== false && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        $items = [];
        $lineNumber = 0;

        foreach ($lines as $line) {
            $lineNumber++;
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            // Пропускаем заголовок
            if ($lineNumber === 1 && $this->recognizer->isHeader($trimmed)) {
                continue;
            }

            $items[] = $this->recognizer->recognize($trimmed, $lineNumber);
        }

        return $items;
    }
}
