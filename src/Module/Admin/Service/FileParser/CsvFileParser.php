<?php

declare(strict_types=1);

namespace App\Module\Admin\Service\FileParser;

/**
 * Парсер CSV-файлов.
 *
 * Поддерживает разделители: запятая, точка с запятой, табуляция.
 * Автоматически пропускает строку-заголовок.
 */
final class CsvFileParser implements FileParserInterface
{
    public function __construct(
        private readonly ValueRecognizer $recognizer,
    ) {}

    public function supports(string $extension): bool
    {
        return $extension === 'csv';
    }

    public function parse(string $filePath): array
    {
        $content = $this->readFileWithEncoding($filePath);
        $lines = explode("\n", $content);
        $items = [];
        $lineNumber = 0;

        foreach ($lines as $line) {
            $lineNumber++;
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            // Определяем разделитель и берём первый столбец
            $value = $this->extractFirstColumn($trimmed);

            // Пропускаем заголовок
            if ($lineNumber === 1 && $this->recognizer->isHeader($value)) {
                continue;
            }

            $items[] = $this->recognizer->recognize($value, $lineNumber);
        }

        return $items;
    }

    /**
     * Извлекает первый столбец из CSV-строки.
     */
    private function extractFirstColumn(string $line): string
    {
        // Определяем разделитель: ; или , или tab
        $delimiter = ',';
        if (str_contains($line, ';')) {
            $delimiter = ';';
        } elseif (str_contains($line, "\t")) {
            $delimiter = "\t";
        }

        $columns = str_getcsv($line, $delimiter);

        return trim($columns[0] ?? '', " \t\n\r\0\x0B\"'");
    }

    private function readFileWithEncoding(string $filePath): string
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            return '';
        }

        // Удаляем BOM если есть
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        // Конвертируем из Windows-1251 если нужно
        $encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1251', 'ISO-8859-1'], true);
        if ($encoding !== false && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        // Нормализуем переводы строк
        return str_replace("\r\n", "\n", $content);
    }
}
