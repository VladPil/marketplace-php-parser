<?php

declare(strict_types=1);

namespace App\Shared\Contract;

use App\Shared\DTO\ProductData;
use App\Shared\DTO\ReviewData;

interface ProductStorageInterface
{
    /**
     * Сохраняет товар вместе с его отзывами в транзакции.
     *
     * @param ProductData  $product Данные товара
     * @param ReviewData[] $reviews Массив отзывов к товару
     * @param string       $taskId  Идентификатор задачи парсинга
     * @return int Идентификатор сохранённого товара
     */
    public function saveProductWithReviews(ProductData $product, array $reviews, string $taskId): int;

    /**
     * Создаёт или обновляет товар (upsert).
     *
     * @param ProductData $product Данные товара
     * @param string      $taskId  Идентификатор задачи парсинга
     * @return int Идентификатор товара
     */
    public function upsertProduct(ProductData $product, string $taskId): int;

    /**
     * Находит внутренний ID товара по внешнему идентификатору.
     *
     * @param int    $externalId  Внешний идентификатор товара
     * @param string $marketplace Маркетплейс
     * @return int|null Внутренний ID или null
     */
    public function getProductIdByExternalId(int $externalId, string $marketplace = 'ozon'): ?int;

    /**
     * Гарантирует существование записи товара в БД.
     *
     * Создаёт минимальную запись (external_id, marketplace) если товара нет.
     * Если товар уже существует — не перезаписывает данные, возвращает текущий ID.
     *
     * @param int    $externalId  Внешний идентификатор товара
     * @param string $marketplace Маркетплейс
     * @param string $taskId      Идентификатор задачи парсинга
     * @return int Внутренний ID товара
     */
    public function ensureProductExists(int $externalId, string $marketplace, string $taskId): int;

    /**
     * Сохраняет отзывы для существующего товара без перезаписи данных товара.
     *
     * Обновляет только review_count и добавляет новые отзывы.
     * Используется задачей reviews, чтобы не затирать данные карточки товара.
     *
     * @param int          $productId Внутренний ID товара
     * @param ReviewData[] $reviews   Массив отзывов
     * @param string       $taskId    Идентификатор задачи парсинга
     */
    public function saveReviewsForProduct(int $productId, array $reviews, string $taskId): void;

    /**
     * Проверяет, заполнен ли товар (title + price).
     *
     * Используется для пропуска повторного парсинга когда товар уже собран.
     *
     * @param int    $externalId  Внешний идентификатор товара
     * @param string $marketplace Маркетплейс
     * @return bool true если товар заполнен
     */
    public function isProductPopulated(int $externalId, string $marketplace): bool;

    /**
     * Сохраняет отзывы для товара, найденного по external_id.
     *
     * Используется при умном rerun: товар уже заполнен, нужно дособрать отзывы.
     *
     * @param int          $externalId  Внешний идентификатор товара
     * @param string       $marketplace Маркетплейс
     * @param ReviewData[] $reviews     Массив отзывов
     * @param string       $taskId      Идентификатор задачи парсинга
     */
    public function saveReviewsForExistingProduct(int $externalId, string $marketplace, array $reviews, string $taskId): void;
}
