<?php

declare(strict_types=1);

namespace App\Module\Parser\Marketplace\Ozon;

/**
 * Парсер HTML-страницы товара Ozon (SSR page 1).
 *
 * Извлекает данные из реальной структуры Ozon HTML:
 * - Заголовок из тега <h1>
 * - Цены из HTML-encoded JSON (cardPrice, price, originalPrice)
 * - Рейтинг и количество отзывов из текста "X.X · N отзывов"
 * - Бренд из JSON-поля "brand":"..."
 * - Галерея изображений из multimedia URL на ir.ozone.ru
 * - Категории из NUXT state (layoutTrackingInfo → hierarchy)
 */
final class OzonHtmlParser
{
    /**
     * Извлекает все данные из HTML одним вызовом.
     *
     * @param string $html Сырой HTML страницы товара
     * @return array{
     *     schemaOrg: array|null,
     *     galleryImages: string[],
     *     category: array|null,
     *     originalPrice: float|null
     * }
     */
    public function parseAll(string $html): array
    {
        $productData = $this->parseProductData($html);
        $originalPrice = $this->parseOriginalPrice($html);

        return [
            'schemaOrg' => $productData,
            'galleryImages' => $this->parseGalleryImages($html),
            'category' => $this->parseCategoryHierarchy($html),
            'originalPrice' => $originalPrice ?? ($productData['originalPrice'] ?? null),
        ];
    }

    /**
     * Парсит данные товара из HTML (заголовок, цены, рейтинг, бренд).
     *
     * Приоритет источников:
     * 1. Schema.org JSON-LD (наиболее структурированный источник)
     * 2. <h1> для названия, HTML-encoded JSON для цен
     * 3. Паттерн "X.X · N отзывов" для рейтинга
     * 4. JSON-поле "brand":"..." для бренда
     *
     * @return array|null Массив с данными товара или null
     */
    public function parseProductData(string $html): ?array
    {
        $result = [
            'name' => null,
            'price' => null,
            'originalPrice' => null,
            'rating' => null,
            'reviewCount' => null,
            'brand' => null,
            'description' => null,
            'image' => null,
        ];

        $hasData = false;

        // Schema.org JSON-LD — основной структурированный источник
        $schemaOrg = $this->parseSchemaOrgJsonLd($html);
        if ($schemaOrg !== null) {
            $result['name'] = $schemaOrg['name'] ?? null;
            $result['brand'] = $schemaOrg['brand'] ?? null;
            $result['description'] = $schemaOrg['description'] ?? null;
            $result['image'] = $schemaOrg['image'] ?? null;
            if (isset($schemaOrg['aggregateRating'])) {
                $result['rating'] = isset($schemaOrg['aggregateRating']['ratingValue'])
                    ? (float) $schemaOrg['aggregateRating']['ratingValue']
                    : null;
                $result['reviewCount'] = isset($schemaOrg['aggregateRating']['reviewCount'])
                    ? (int) $schemaOrg['aggregateRating']['reviewCount']
                    : null;
            }
            if (isset($schemaOrg['offers']['price'])) {
                $result['price'] = (float) $schemaOrg['offers']['price'];
            }
            $hasData = $result['name'] !== null;
        }

        // Fallback: название из <h1>
        if ($result['name'] === null && preg_match('/<h1[^>]*>(.*?)<\/h1>/si', $html, $match)) {
            $name = trim(strip_tags($match[1]));
            if ($name !== '') {
                $result['name'] = $name;
                $hasData = true;
            }
        }

        // Цены из HTML-encoded JSON (формат &quot;cardPrice&quot;:&quot;265 ₽&quot;)
        $prices = $this->parsePricesFromEncodedJson($html);
        if ($prices !== null) {
            $result['price'] ??= $prices['cardPrice'] ?? $prices['price'] ?? null;
            $result['originalPrice'] = $prices['originalPrice'] ?? null;
            $hasData = true;
        }

        // Рейтинг и количество отзывов: паттерн "4.9 · 148 983 отзыв"
        if ($result['rating'] === null && preg_match('/(\d\.\d)\s*[·•]\s*([\d\s]+)\s*отзыв/u', $html, $match)) {
            $result['rating'] = (float) $match[1];
            $reviewCount = preg_replace('/\s+/', '', $match[2]);
            $result['reviewCount'] = (int) $reviewCount;
            $hasData = true;
        }

        // Бренд — fallback из JSON-поля
        if ($result['brand'] === null && preg_match('/"brand"\s*:\s*"([^"]+)"/', $html, $match)) {
            $result['brand'] = $match[1];
            $hasData = true;
        }

        // Основное изображение — fallback из multimedia URL
        if ($result['image'] === null && preg_match('#https?://ir\.ozone\.ru/s3/multimedia-\d+-\w+/wc1000/\d+\.\w+#i', $html, $match)) {
            $result['image'] = $match[0];
            $hasData = true;
        }

        return $hasData ? $result : null;
    }

    /**
     * Извлекает данные из Schema.org JSON-LD блока.
     *
     * @return array|null Декодированный JSON-LD или null
     */
    private function parseSchemaOrgJsonLd(string $html): ?array
    {
        // Ozon добавляет nonce к script: <script nonce="" type="application/ld+json">
        if (!preg_match('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $match)) {
            return null;
        }

        $data = json_decode($match[1], true);
        if (!is_array($data) || ($data['@type'] ?? '') !== 'Product') {
            return null;
        }

        // Нормализация brand (может быть строкой или объектом)
        if (isset($data['brand']) && is_array($data['brand'])) {
            $data['brand'] = $data['brand']['name'] ?? null;
        }

        // Нормализация image (может быть массивом)
        if (isset($data['image']) && is_array($data['image'])) {
            $data['image'] = $data['image'][0] ?? null;
        }

        return $data;
    }

    /**
     * Извлекает URL изображений галереи из HTML.
     *
     * Находит уникальные ID multimedia-изображений из миниатюр (wc50/wc100)
     * и формирует полноразмерные URL (wc1000).
     *
     * @return string[] Массив полноразмерных URL изображений
     */
    public function parseGalleryImages(string $html): array
    {
        // Паттерн: ir.ozone.ru/s3/multimedia-1-X/wc50/ID.jpg (или wc100)
        if (!preg_match_all('#https?://ir\.ozone\.ru/s3/(multimedia-\d+-\w+)/wc(?:50|100)/(\d+)\.\w+#i', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        // Дедупликация по ID изображения, сохраняем порядок
        $seen = [];
        $urls = [];
        foreach ($matches as $match) {
            $imageId = $match[2];
            if (isset($seen[$imageId])) {
                continue;
            }
            $seen[$imageId] = true;
            $pathPrefix = $match[1]; // multimedia-1-f
            $urls[] = sprintf('https://ir.ozone.ru/s3/%s/wc1000/%s.jpg', $pathPrefix, $imageId);
        }

        return $urls;
    }

    /**
     * Извлекает иерархию категорий из NUXT state layoutTrackingInfo.
     *
     * @return array{categoryId: int|null, categoryName: string|null, hierarchy: string}|null
     */
    public function parseCategoryHierarchy(string $html): ?array
    {
        // layoutTrackingInfo может быть в NUXT state или в JSON внутри скрипта
        if (!preg_match('/layoutTrackingInfo["\s]*:["\s]*(\{.*?\})["\s]*[,}]/s', $html, $match)) {
            // Альтернативный формат: строка JSON внутри экранированного значения
            if (!preg_match('/layoutTrackingInfo\\\\?":\\\\?"({.*?})\\\\?"/s', $html, $match)) {
                return null;
            }
        }

        $trackingStr = $match[1];
        // Убираем возможные экранированные кавычки
        $trackingStr = str_replace(['\\\"', "\\'"], ['"', "'"], $trackingStr);

        $tracking = json_decode($trackingStr, true);
        if (!is_array($tracking)) {
            // Попробуем найти поля регулярками
            return $this->parseCategoryFromRawHtml($html);
        }

        $categoryId = isset($tracking['categoryId']) ? (int) $tracking['categoryId'] : null;
        $categoryName = $tracking['categoryName'] ?? null;
        // Ozon экранирует слэши как \u002F
        $hierarchy = $tracking['hierarchy'] ?? '';
        $hierarchy = str_replace('\\u002F', '/', $hierarchy);

        if ($categoryId === null && $hierarchy === '') {
            return null;
        }

        return [
            'categoryId' => $categoryId,
            'categoryName' => $categoryName,
            'hierarchy' => $hierarchy,
        ];
    }

    /**
     * Извлекает originalPrice (зачёркнутую цену) из HTML.
     */
    public function parseOriginalPrice(string $html): ?float
    {
        // Основной паттерн: HTML-encoded JSON
        $prices = $this->parsePricesFromEncodedJson($html);
        if ($prices !== null && isset($prices['originalPrice'])) {
            return $prices['originalPrice'];
        }

        // Fallback: "originalPrice":"548 ₽" в обычном JSON
        if (preg_match('/"originalPrice"\s*:\s*"([^"]+)"/', $html, $match)) {
            return $this->cleanPrice($match[1]);
        }

        return null;
    }

    /**
     * Извлекает цены из HTML-encoded JSON блока.
     *
     * Ozon рендерит цены в формате: &quot;cardPrice&quot;:&quot;265 ₽&quot;
     * Это HTML-encoded JSON внутри атрибутов виджетов.
     *
     * @return array{cardPrice: float|null, price: float|null, originalPrice: float|null}|null
     */
    private function parsePricesFromEncodedJson(string $html): ?array
    {
        // Ищем паттерн с HTML entities
        $pattern = '/&quot;cardPrice&quot;:&quot;([^&]+)&quot;.*?'
            . '&quot;price&quot;:&quot;([^&]+)&quot;.*?'
            . '&quot;originalPrice&quot;:&quot;([^&]+)&quot;/s';

        if (!preg_match($pattern, $html, $match)) {
            // Fallback: обычный JSON формат
            $pattern2 = '/"cardPrice"\s*:\s*"([^"]+)".*?"price"\s*:\s*"([^"]+)".*?"originalPrice"\s*:\s*"([^"]+)"/s';
            if (!preg_match($pattern2, $html, $match)) {
                return null;
            }
        }

        return [
            'cardPrice' => $this->cleanPrice($match[1]),
            'price' => $this->cleanPrice($match[2]),
            'originalPrice' => $this->cleanPrice($match[3]),
        ];
    }

    /**
     * Пытается извлечь категорию из сырого HTML при неудачном JSON-парсинге.
     */
    private function parseCategoryFromRawHtml(string $html): ?array
    {
        $categoryId = null;
        $categoryName = null;
        $hierarchy = '';

        if (preg_match('/["\']categoryId["\']\s*:\s*["\']?(\d+)["\']?/', $html, $m)) {
            $categoryId = (int) $m[1];
        }

        if (preg_match('/["\']categoryName["\']\s*:\s*["\']([^"\']+)["\']/', $html, $m)) {
            $categoryName = $m[1];
        }

        if (preg_match('/["\']hierarchy["\']\s*:\s*["\']([^"\']+)["\']/', $html, $m)) {
            $hierarchy = str_replace('\\u002F', '/', $m[1]);
        }

        if ($categoryId === null && $hierarchy === '') {
            return null;
        }

        return [
            'categoryId' => $categoryId,
            'categoryName' => $categoryName,
            'hierarchy' => $hierarchy,
        ];
    }

    /**
     * Очищает строку цены от символов валюты и пробелов.
     */
    private function cleanPrice(string $priceString): ?float
    {
        // Убираем ₽, пробелы (включая thin space \u2009 и nbsp \u00a0), и прочие символы
        $cleaned = preg_replace('/[^\d.,]/', '', $priceString);
        $cleaned = str_replace(',', '.', $cleaned);

        return $cleaned !== '' ? (float) $cleaned : null;
    }
}
