<?php

declare(strict_types=1);

namespace App\Shared\Contract;

/**
 * Контракт для парсинга отзывов о товаре.
 *
 * Реализации извлекают список отзывов из ответа API
 * и определяют наличие следующей страницы для пагинации.
 */
interface ReviewParserInterface
{
    /**
     * Парсит ответ API и возвращает массив отзывов.
     *
     * @param array $response Сырой ответ от API маркетплейса
     * @return array Массив нормализованных отзывов
     */
    public function parse(array $response): array;

    /**
     * Проверяет, есть ли следующая страница отзывов.
     *
     * @param array $response Сырой ответ от API маркетплейса
     * @return bool true, если доступна следующая страница
     */
    public function hasNextPage(array $response): bool;

    /**
     * Извлекает URL следующей страницы (курсор) из ответа API.
     *
     * @param array $response Сырой ответ от API маркетплейса
     * @return string|null URL следующей страницы или null, если страниц больше нет
     */
    public function getNextPageUrl(array $response): ?string;
}
