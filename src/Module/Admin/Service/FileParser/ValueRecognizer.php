<?php

declare(strict_types=1);

namespace App\Module\Admin\Service\FileParser;

/**
 * Распознаёт тип значения строки: URL маркетплейса, external_id, slug.
 */
final class ValueRecognizer
{
    private const string MARKETPLACE_URL_PATTERN = '#ozon\.ru/product/([a-z0-9-]+)-(\d+)#i';
    private const string SLUG_WITH_ID_PATTERN = '#^([a-z0-9][a-z0-9-]*)-(\d{5,})$#';
    private const string NUMERIC_ID_PATTERN = '#^\d{5,}$#';

    /**
     * Список известных заголовков CSV, которые нужно пропускать.
     */
    private const array HEADER_KEYWORDS = ['id', 'url', 'slug', 'external_id', 'product', 'link', 'ссылка', 'товар', 'идентификатор'];

    public function recognize(string $raw, int $lineNumber): ParsedItem
    {
        $value = trim($raw);

        if ($value === '') {
            return new ParsedItem(null, null, null, $raw, $lineNumber, 'Пустая строка');
        }

        // URL маркетплейса
        if (preg_match(self::MARKETPLACE_URL_PATTERN, $value, $matches)) {
            $slug = $matches[1] . '-' . $matches[2];

            return new ParsedItem((int) $matches[2], $slug, $value, $raw, $lineNumber);
        }

        // Slug с числовым ID на конце (naushniki-bluetooth-123456789)
        if (preg_match(self::SLUG_WITH_ID_PATTERN, $value, $matches)) {
            return new ParsedItem((int) $matches[2], $value, null, $raw, $lineNumber);
        }

        // Числовой external_id
        if (preg_match(self::NUMERIC_ID_PATTERN, $value)) {
            return new ParsedItem((int) $value, null, null, $raw, $lineNumber);
        }

        return new ParsedItem(
            null,
            null,
            null,
            $raw,
            $lineNumber,
            'Не удалось распознать: ожидается URL маркетплейса, external_id (число от 5 цифр) или slug вида naushniki-123456',
        );
    }

    /**
     * Проверяет, является ли строка заголовком CSV/TXT.
     */
    public function isHeader(string $value): bool
    {
        $lower = mb_strtolower(trim($value));

        foreach (self::HEADER_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
