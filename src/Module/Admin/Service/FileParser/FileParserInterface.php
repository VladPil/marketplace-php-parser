<?php

declare(strict_types=1);

namespace App\Module\Admin\Service\FileParser;

/**
 * Интерфейс парсера загруженного файла с товарами.
 */
interface FileParserInterface
{
    /**
     * Поддерживает ли парсер данное расширение файла.
     */
    public function supports(string $extension): bool;

    /**
     * Парсит файл и возвращает массив распознанных записей.
     *
     * @return ParsedItem[]
     */
    public function parse(string $filePath): array;
}
