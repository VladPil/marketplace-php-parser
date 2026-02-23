<?php

declare(strict_types=1);

namespace App\Module\Admin\Service\FileParser;

/**
 * Парсер JSON-файлов.
 *
 * Поддерживает форматы:
 * - Массив: [123, "slug-123", "https://example.com/product/..."]
 * - Объект с ключом: {"items": [...]} / {"products": [...]} / {"urls": [...]}
 * - Массив объектов: [{"external_id": 123}, {"url": "..."}]
 */
final class JsonFileParser implements FileParserInterface
{
    private const array KNOWN_ARRAY_KEYS = ['items', 'products', 'urls', 'data', 'list'];

    public function __construct(
        private readonly ValueRecognizer $recognizer,
    ) {}

    public function supports(string $extension): bool
    {
        return $extension === 'json';
    }

    public function parse(string $filePath): array
    {
        $content = file_get_contents($filePath);

        if ($content === false || trim($content) === '') {
            return [];
        }

        // Удаляем BOM
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                new ParsedItem(null, null, null, 'JSON', 1, 'Невалидный JSON: ' . json_last_error_msg()),
            ];
        }

        $values = $this->extractValues($data);
        $items = [];

        foreach ($values as $index => $value) {
            $items[] = $this->recognizer->recognize((string) $value, $index + 1);
        }

        return $items;
    }

    /**
     * Извлекает плоский массив значений из JSON-структуры.
     *
     * @return list<string>
     */
    private function extractValues(mixed $data): array
    {
        // Простой массив: [123, "slug", "url"]
        if (is_array($data) && array_is_list($data)) {
            return $this->flattenArray($data);
        }

        // Объект: ищем известный ключ с массивом
        if (is_array($data)) {
            foreach (self::KNOWN_ARRAY_KEYS as $key) {
                if (isset($data[$key]) && is_array($data[$key])) {
                    return $this->flattenArray($data[$key]);
                }
            }

            // Fallback: берём первый массив из значений
            foreach ($data as $value) {
                if (is_array($value) && array_is_list($value)) {
                    return $this->flattenArray($value);
                }
            }
        }

        return [];
    }

    /**
     * Конвертирует массив (возможно вложенных объектов) в плоский массив строк.
     *
     * @return list<string>
     */
    private function flattenArray(array $data): array
    {
        $result = [];

        foreach ($data as $item) {
            if (is_scalar($item)) {
                $result[] = (string) $item;
            } elseif (is_array($item)) {
                // Объект типа {"external_id": 123, "slug": "..."}
                $value = $item['external_id'] ?? $item['id'] ?? $item['url'] ?? $item['slug'] ?? null;

                if ($value !== null) {
                    $result[] = (string) $value;
                }
            }
        }

        return $result;
    }
}
