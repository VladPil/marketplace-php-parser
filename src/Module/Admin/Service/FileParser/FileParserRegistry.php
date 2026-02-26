<?php

declare(strict_types=1);

namespace App\Module\Admin\Service\FileParser;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Реестр парсеров файлов. Выбирает подходящий парсер по расширению.
 */
final class FileParserRegistry
{
    private const array ALLOWED_EXTENSIONS = ['csv', 'txt', 'json'];
    private const int MAX_FILE_SIZE = 2 * 1024 * 1024; // 2 МБ
    private const int MAX_ITEMS = 1000;

    /** @var FileParserInterface[] */
    private readonly array $parsers;

    public function __construct(
        CsvFileParser $csvParser,
        TxtFileParser $txtParser,
        JsonFileParser $jsonParser,
    ) {
        $this->parsers = [$csvParser, $txtParser, $jsonParser];
    }

    /**
     * Валидирует загруженный файл и возвращает ошибку или null.
     */
    public function validateUpload(UploadedFile $file): ?string
    {
        if (!$file->isValid()) {
            return 'Файл повреждён или не был загружен: ' . $file->getErrorMessage();
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return sprintf(
                'Формат "%s" не поддерживается. Допустимые форматы: %s',
                $extension,
                implode(', ', self::ALLOWED_EXTENSIONS),
            );
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return sprintf('Файл слишком большой (%.1f МБ). Максимум: 2 МБ', $file->getSize() / 1024 / 1024);
        }

        return null;
    }

    /**
     * Парсит файл и возвращает результат с валидацией количества записей.
     *
     * @return array{items: ParsedItem[], valid: int, invalid: int, total: int, error: string|null}
     */
    public function parseFile(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $parser = $this->getParser($extension);

        if ($parser === null) {
            return [
                'items' => [],
                'valid' => 0,
                'invalid' => 0,
                'total' => 0,
                'error' => 'Не найден парсер для формата: ' . $extension,
            ];
        }

        $items = $parser->parse($file->getPathname());

        if (count($items) > self::MAX_ITEMS) {
            return [
                'items' => array_slice($items, 0, self::MAX_ITEMS),
                'valid' => 0,
                'invalid' => 0,
                'total' => count($items),
                'error' => sprintf(
                    'Слишком много записей (%d). Максимум: %d. Разбейте файл на части.',
                    count($items),
                    self::MAX_ITEMS,
                ),
            ];
        }

        // Дедупликация по external_id
        $items = $this->deduplicateItems($items);

        $valid = count(array_filter($items, static fn(ParsedItem $item) => $item->isValid()));
        $invalid = count($items) - $valid;

        return [
            'items' => $items,
            'valid' => $valid,
            'invalid' => $invalid,
            'total' => count($items),
            'error' => null,
        ];
    }

    private function getParser(string $extension): ?FileParserInterface
    {
        return array_find($this->parsers, fn($parser) => $parser->supports($extension));

    }

    /**
     * Удаляет дубликаты по external_id, оставляя первое вхождение.
     *
     * @param ParsedItem[] $items
     * @return ParsedItem[]
     */
    private function deduplicateItems(array $items): array
    {
        $seen = [];
        $result = [];

        foreach ($items as $item) {
            if (!$item->isValid()) {
                $result[] = $item;

                continue;
            }

            $key = $item->externalId ?? $item->slug;

            if (isset($seen[$key])) {
                $result[] = new ParsedItem(
                    $item->externalId,
                    $item->slug,
                    $item->url,
                    $item->rawValue,
                    $item->lineNumber,
                    sprintf('Дубликат (первое вхождение на строке %d)', $seen[$key]),
                );

                continue;
            }

            $seen[$key] = $item->lineNumber;
            $result[] = $item;
        }

        return $result;
    }
}
