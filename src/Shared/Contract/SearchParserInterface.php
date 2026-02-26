<?php

declare(strict_types=1);

namespace App\Shared\Contract;

/**
 * Контракт для парсинга результатов поиска.
 *
 * Реализации извлекают список найденных товаров из ответа API
 * и определяют наличие следующей страницы для пагинации.
 */
interface SearchParserInterface
{
    /**
     * Парсит ответ API поиска и возвращает массив найденных товаров.
     *
     * @param array $response Сырой ответ от API маркетплейса
     * @return array Массив нормализованных результатов поиска
     */
    public function parse(array $response): array;

    /**
     * Проверяет, есть ли следующая страница результатов поиска.
     *
     * @param array $response Сырой ответ от API маркетплейса
     * @return bool true, если доступна следующая страница
     */
    public function hasNextPage(array $response): bool;
}
