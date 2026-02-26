<?php

declare(strict_types=1);

namespace App\Module\Parser\Marketplace\Ozon;

use App\Shared\Contract\CategoryExtractorInterface;

final class OzonCategoryExtractor implements CategoryExtractorInterface
{
    public function __construct(
        private readonly OzonWidgetExtractor $widgetExtractor,
    ) {}

    /**
     * Извлекает категории из API-ответа с fallback на HTML-данные.
     *
     * Приоритет: API webBreadCrumbs → HTML layoutTrackingInfo hierarchy.
     *
     * @param array $response API-ответ
     * @param array|null $htmlCategoryData Данные из OzonHtmlParser::parseCategoryHierarchy()
     */
    public function extract(array $response, ?array $htmlCategoryData = null): array
    {
        $widgets = $this->widgetExtractor->extractWidgets($response);
        $breadcrumbs = $this->widgetExtractor->findWidget($widgets, 'webBreadCrumbs');

        if ($breadcrumbs !== null) {
            return $this->extractFromBreadcrumbs($breadcrumbs);
        }

        // Fallback: HTML layoutTrackingInfo hierarchy
        if ($htmlCategoryData !== null) {
            return $this->extractFromHtmlHierarchy($htmlCategoryData);
        }

        return [];
    }

    /**
     * Извлекает категории из API breadcrumbs.
     */
    private function extractFromBreadcrumbs(array $breadcrumbs): array
    {
        $categories = [];
        $parentId = null;
        $path = '';

        foreach ($breadcrumbs['crumbs'] ?? [] as $depth => $crumb) {
            $externalId = $crumb['id'] ?? null;
            $name = trim($crumb['text'] ?? '');

            if ($externalId === null || $name === '') {
                continue;
            }

            $path = $path !== '' ? $path . '/' . $name : $name;

            $categories[] = [
                'external_id' => (int) $externalId,
                'marketplace' => 'ozon',
                'name' => $name,
                'parent_external_id' => $parentId,
                'depth' => $depth,
                'path' => $path,
            ];

            $parentId = (int) $externalId;
        }

        return $categories;
    }

    /**
     * Извлекает категории из HTML hierarchy строки.
     *
     * Строка формата: "Обувь/Женская обувь/Сабо и мюли/Crocs"
     * Последний сегмент — бренд, не категория.
     */
    private function extractFromHtmlHierarchy(array $htmlCategoryData): array
    {
        $hierarchy = $htmlCategoryData['hierarchy'] ?? '';
        if ($hierarchy === '') {
            return [];
        }

        $segments = array_map('trim', explode('/', $hierarchy));
        // Последний сегмент hierarchy — обычно бренд, пропускаем
        if (count($segments) > 1) {
            array_pop($segments);
        }

        $categoryId = $htmlCategoryData['categoryId'] ?? null;
        $categories = [];
        $parentId = null;
        $path = '';

        foreach ($segments as $depth => $name) {
            if ($name === '') {
                continue;
            }

            $path = $path !== '' ? $path . '/' . $name : $name;

            // Для последнего сегмента используем categoryId из HTML, если есть
            $extId = ($depth === count($segments) - 1 && $categoryId !== null)
                ? $categoryId
                : crc32($path); // Генерируем стабильный ID из пути для промежуточных уровней

            $categories[] = [
                'external_id' => (int) $extId,
                'marketplace' => 'ozon',
                'name' => $name,
                'parent_external_id' => $parentId,
                'depth' => $depth,
                'path' => $path,
            ];

            $parentId = (int) $extId;
        }

        return $categories;
    }
}
