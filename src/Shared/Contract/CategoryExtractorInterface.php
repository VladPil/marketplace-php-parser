<?php

declare(strict_types=1);

namespace App\Shared\Contract;

/**
 * Контракт для извлечения категорий из ответа маркетплейса.
 *
 * Реализации должны разбирать структуру ответа API
 * и возвращать нормализованный список категорий.
 */
interface CategoryExtractorInterface
{
    /**
     * Извлекает список категорий из ответа API.
     *
     * @param array $response Сырой ответ от API маркетплейса
     * @param array|null $htmlCategoryData Данные категорий из HTML (fallback)
     * @return array Массив извлечённых категорий
     */
    public function extract(array $response, ?array $htmlCategoryData = null): array;
}
