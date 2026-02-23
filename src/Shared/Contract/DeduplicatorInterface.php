<?php

declare(strict_types=1);

namespace App\Shared\Contract;

/**
 * Контракт для дедупликации обработанных элементов.
 *
 * Выделен из QueuePublisherInterface в соответствии с ISP.
 * Предотвращает повторную обработку уже обработанных товаров и отзывов.
 */
interface DeduplicatorInterface
{
    /**
     * Отмечает товар как обработанный в рамках задачи.
     *
     * @param string $taskId Идентификатор задачи
     * @param int $externalId Внешний идентификатор товара
     */
    public function markProductSeen(string $taskId, int $externalId): void;

    /**
     * Проверяет был ли товар уже обработан.
     *
     * @param string $taskId Идентификатор задачи
     * @param int $externalId Внешний идентификатор товара
     */
    public function isProductSeen(string $taskId, int $externalId): bool;

    /**
     * Отмечает отзыв как обработанный в рамках задачи.
     *
     * @param string $taskId Идентификатор задачи
     * @param string|int $reviewId Внешний идентификатор отзыва (UUID или число)
     */
    public function markReviewSeen(string $taskId, string|int $reviewId): void;

    /**
     * Проверяет был ли отзыв уже обработан.
     *
     * @param string $taskId Идентификатор задачи
     * @param string|int $reviewId Внешний идентификатор отзыва (UUID или число)
     */
    public function isReviewSeen(string $taskId, string|int $reviewId): bool;
}
