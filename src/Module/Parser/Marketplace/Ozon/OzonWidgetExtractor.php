<?php

declare(strict_types=1);

namespace App\Module\Parser\Marketplace\Ozon;

final class OzonWidgetExtractor
{
    /** @return array<string, mixed> */
    public function extractWidgets(array $response): array
    {
        $widgets = [];
        $widgetStates = $response['widgetStates'] ?? [];

        foreach ($widgetStates as $key => $jsonString) {
            if (!is_string($jsonString)) {
                continue;
            }
            $decoded = json_decode($jsonString, true);
            if (is_array($decoded)) {
                $widgets[$key] = $decoded;
            }
        }

        return $widgets;
    }

    public function findWidget(array $widgets, string $prefix): ?array
    {
        foreach ($widgets as $key => $widget) {
            if (str_starts_with($key, $prefix)) {
                return $widget;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    public function findWidgets(array $widgets, string $prefix): array
    {
        $found = [];
        foreach ($widgets as $key => $widget) {
            if (str_starts_with($key, $prefix)) {
                $found[$key] = $widget;
            }
        }
        return $found;
    }
}
