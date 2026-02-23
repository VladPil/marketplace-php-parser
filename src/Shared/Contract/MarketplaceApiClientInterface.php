<?php

declare(strict_types=1);

namespace App\Shared\Contract;

/**
 * Контракт для HTTP-клиента маркетплейса.
 *
 * Реализации выполняют запросы к API маркетплейса
 * для получения страниц, поиска товаров, получения
 * карточек товаров и отзывов.
 */
interface MarketplaceApiClientInterface
{
    /**
     * Загружает произвольную страницу по указанному пути.
     *
     * @param string $path        Путь запроса (относительный URL)
     * @param array  $queryParams GET-параметры запроса
     * @return array Декодированный ответ API
     */
    public function fetchPage(string $path, array $queryParams = []): array;

    /**
     * Выполняет поиск товаров по текстовому запросу.
     *
     * @param string $query Поисковый запрос
     * @param int    $page  Номер страницы результатов (начиная с 1)
     * @return array Массив результатов поиска
     */
    public function searchProducts(string $query, int $page = 1): array;

    /**
     * Получает данные карточки товара.
     *
     * @param string $slug       Человекочитаемый идентификатор (slug) товара
     * @param int    $externalId Внешний числовой идентификатор товара
     * @return array Данные карточки товара
     */
    public function fetchProduct(string $slug, int $externalId): array;

    /**
     * Загружает HTML-страницу товара (SSR-рендер).
     *
     * Используется для извлечения Schema.org, галереи, категорий —
     * данных, которые присутствуют только в HTML (page 1), но не в API (page 2).
     *
     * @param string $slug       Человекочитаемый идентификатор (slug) товара
     * @param int    $externalId Внешний числовой идентификатор товара
     * @return string Сырой HTML страницы товара
     */
    public function fetchProductHtml(string $slug, int $externalId): string;

    /**
     * Получает отзывы о товаре.
     *
     * @param string $slug       Человекочитаемый идентификатор (slug) товара
     * @param int    $externalId Внешний числовой идентификатор товара
     * @param int    $page       Номер страницы отзывов (начиная с 1)
     * @return array Массив отзывов
     *
     * @deprecated Используйте fetchReviewsFirstPage() + fetchReviewsByNextPage() для курсорной пагинации
     */
    public function fetchReviews(string $slug, int $externalId, int $page = 1): array;

    /**
     * Загружает первую страницу отзывов товара.
     *
     * Формирует начальный URL с tab=reviews для получения первой страницы
     * и курсора nextPage для последующей пагинации.
     *
     * @param string $slug       Человекочитаемый идентификатор (slug) товара
     * @param int    $externalId Внешний числовой идентификатор товара
     * @return array Сырой ответ API (содержит виджеты и nextPage)
     */
    public function fetchReviewsFirstPage(string $slug, int $externalId): array;

    /**
     * Загружает следующую страницу отзывов по курсору nextPage.
     *
     * @param string $nextPageUrl URL следующей страницы из предыдущего ответа
     * @return array Сырой ответ API (содержит виджеты и nextPage)
     */
    public function fetchReviewsByNextPage(string $nextPageUrl): array;
}
