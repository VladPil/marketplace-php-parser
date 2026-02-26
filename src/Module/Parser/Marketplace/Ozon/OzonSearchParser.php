<?php

declare(strict_types=1);

namespace App\Module\Parser\Marketplace\Ozon;

use App\Shared\Contract\SearchParserInterface;

final class OzonSearchParser implements SearchParserInterface
{
    public function __construct(
        private readonly OzonWidgetExtractor $widgetExtractor,
    ) {}

    public function parse(array $response): array
    {
        $widgets = $this->widgetExtractor->extractWidgets($response);
        $searchWidget = $this->widgetExtractor->findWidget($widgets, 'searchResultsV2');

        if ($searchWidget === null) {
            return [];
        }

        $products = [];
        foreach ($searchWidget['items'] ?? [] as $item) {
            $mainState = $item['mainState'] ?? [];
            $externalId = $item['id'] ?? null;

            if ($externalId === null) {
                continue;
            }

            $title = '';
            foreach ($mainState as $state) {
                if ((($state['atom'] ?? [])['type'] ?? '') === 'textAtom') {
                    $title = ($state['atom'] ?? [])['textAtom']['text'] ?? '';
                    break;
                }
            }

            $products[] = [
                'external_id' => (int) $externalId,
                'title' => trim($title),
                'url' => ($item['action'] ?? [])['link'] ?? null,
                'marketplace' => 'ozon',
            ];
        }

        return $products;
    }

    public function hasNextPage(array $response): bool
    {
        $widgets = $this->widgetExtractor->extractWidgets($response);
        $paginator = $this->widgetExtractor->findWidget($widgets, 'megaPaginator');

        if ($paginator === null) {
            return false;
        }

        return !empty($paginator['nextPage']);
    }
}
