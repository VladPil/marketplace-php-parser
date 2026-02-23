<?php

declare(strict_types=1);

namespace App\Shared\Contract;

use App\Shared\DTO\CategoryData;

interface CategoryStorageInterface
{
    /**
     * Создаёт или обновляет категории (upsert).
     *
     * @param CategoryData[] $categories Массив категорий
     * @param string|null $taskId Идентификатор задачи парсинга
     * @return array Массив внутренних ID сохранённых категорий
     */
    public function upsertCategories(array $categories, ?string $taskId = null): array;
}
