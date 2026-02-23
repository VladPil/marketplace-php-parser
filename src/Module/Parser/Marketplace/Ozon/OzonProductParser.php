<?php

declare(strict_types=1);

namespace App\Module\Parser\Marketplace\Ozon;

use App\Shared\Contract\ProductParserInterface;

final class OzonProductParser implements ProductParserInterface
{
    public function __construct(
        private readonly OzonWidgetExtractor $widgetExtractor,
    ) {}

    /**
     * Парсит данные товара из API-ответа с обогащением из HTML.
     *
     * Приоритет данных:
     * - title: HTML Schema.org → API webProductHeading
     * - price: HTML Schema.org → API webPrice
     * - originalPrice: HTML → API webPrice
     * - rating/reviewCount: HTML Schema.org → API webReviewProductScore
     * - image_url: HTML Schema.org → API webGallery
     * - image_urls: HTML галерея → API webGallery
     * - characteristics: только API (page 2)
     * - brand/description: HTML Schema.org (новые поля)
     *
     * @param array $response API-ответ (page 2)
     * @param int $externalId Внешний ID товара
     * @param string $slug Slug товара
     * @param array|null $htmlData Данные из OzonHtmlParser::parseAll()
     */
    public function parse(array $response, int $externalId, string $slug = '', ?array $htmlData = null): ?array
    {
        $widgets = $this->widgetExtractor->extractWidgets($response);
        $schemaOrg = $htmlData['schemaOrg'] ?? null;

        $webProductMain = $this->widgetExtractor->findWidget($widgets, 'webProductHeading');
        $webPrice = $this->widgetExtractor->findWidget($widgets, 'webPrice');
        $webGallery = $this->widgetExtractor->findWidget($widgets, 'webGallery');
        $characteristics = $this->parseCharacteristics($widgets);

        // Title: HTML Schema.org → API
        $title = $schemaOrg['name'] ?? ($webProductMain['title'] ?? '');
        if ($title === '') {
            return null;
        }

        // Price: HTML Schema.org → API
        $price = null;
        $originalPrice = null;
        if ($schemaOrg !== null && $schemaOrg['price'] !== null) {
            $price = $schemaOrg['price'];
        } elseif ($webPrice !== null) {
            $price = $this->extractPrice($webPrice['price'] ?? null);
        }

        // OriginalPrice: HTML → API
        if ($htmlData !== null && ($htmlData['originalPrice'] ?? null) !== null) {
            $originalPrice = $htmlData['originalPrice'];
        } elseif ($webPrice !== null) {
            $originalPrice = $this->extractPrice($webPrice['originalPrice'] ?? null);
        }

        // Изображения: HTML галерея → API
        $imageUrl = null;
        $imageUrls = [];
        $htmlGallery = $htmlData['galleryImages'] ?? [];
        if (!empty($htmlGallery)) {
            $imageUrls = $htmlGallery;
            $imageUrl = $imageUrls[0] ?? null;
        } elseif ($webGallery !== null) {
            foreach ($webGallery['images'] ?? [] as $image) {
                $url = $image['src'] ?? $image['url'] ?? null;
                if ($url !== null) {
                    $imageUrls[] = $url;
                }
            }
            $imageUrl = $imageUrls[0] ?? null;
        }

        // Основное изображение из Schema.org, если галерея не дала результатов
        if ($imageUrl === null && $schemaOrg !== null && ($schemaOrg['image'] ?? null) !== null) {
            $imageUrl = $schemaOrg['image'];
            if (empty($imageUrls)) {
                $imageUrls = [$imageUrl];
            }
        }

        // URL товара
        $productUrl = null;
        if ($slug !== '') {
            $productUrl = sprintf('https://www.ozon.ru/product/%s-%d/', $slug, $externalId);
        }

        // Rating/ReviewCount: HTML Schema.org → API
        $rating = null;
        $reviewCount = 0;
        if ($schemaOrg !== null && $schemaOrg['rating'] !== null) {
            $rating = $schemaOrg['rating'];
            $reviewCount = $schemaOrg['reviewCount'] ?? 0;
        } else {
            $reviewWidget = $this->widgetExtractor->findWidget($widgets, 'webReviewProductScore');
            if ($reviewWidget !== null) {
                $rating = isset($reviewWidget['score']) ? (float) $reviewWidget['score'] : null;
                $reviewCount = (int) ($reviewWidget['count'] ?? 0);
            }
        }

        $result = [
            'external_id' => $externalId,
            'marketplace' => 'ozon',
            'title' => trim($title),
            'url' => $productUrl,
            'price' => $price,
            'original_price' => $originalPrice,
            'rating' => $rating,
            'review_count' => $reviewCount,
            'image_url' => $imageUrl,
            'image_urls' => $imageUrls,
            'characteristics' => $characteristics,
            'brand' => $schemaOrg['brand'] ?? null,
            'description' => $schemaOrg['description'] ?? null,
        ];
        return $result;
    }

    private function parseCharacteristics(array $widgets): array
    {
        $charWidget = $this->widgetExtractor->findWidget($widgets, 'webCharacteristics');
        if ($charWidget === null) {
            return [];
        }

        $result = [];
        $groups = $charWidget['characteristics'] ?? [];
        foreach ($groups as $group) {
            $groupName = trim($group['title'] ?? 'Другое');
            $result[$groupName] = [];
            foreach ($group['short'] ?? [] as $item) {
                $key = trim($item['name'] ?? '');
                $value = trim($item['values'][0]['text'] ?? '');
                if ($key !== '' && $value !== '') {
                    $result[$groupName][$key] = $value;
                }
            }
        }

        return $result;
    }

    private function extractPrice(?string $priceString): ?float
    {
        if ($priceString === null || $priceString === '') {
            return null;
        }
        $cleaned = preg_replace('/[^\d.,]/', '', $priceString);
        $cleaned = str_replace(',', '.', $cleaned);
        return $cleaned !== '' ? (float) $cleaned : null;
    }
}
