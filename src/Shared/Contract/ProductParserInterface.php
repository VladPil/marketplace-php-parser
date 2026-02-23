<?php

declare(strict_types=1);

namespace App\Shared\Contract;

/**
 * Контракт для парсинга данных товара.
 *
 * Реализации извлекают структурированные данные товара
 * из сырого ответа API маркетплейса.
 */
interface ProductParserInterface
{
    /**
     * Парсит ответ API и возвращает нормализованные данные товара.
     *
     * @param array  $response   Сырой ответ от API маркетплейса
     * @param int    $externalId Внешний числовой идентификатор товара
     * @param string $slug       Slug товара для формирования URL
     * @param array|null $htmlData Дополнительные данные из HTML-страницы (Schema.org, галерея и т.д.)
     * @return array|null Нормализованные данные товара или null, если товар не найден
     */
    public function parse(array $response, int $externalId, string $slug = '', ?array $htmlData = null): ?array;
}
