<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller\Request\ParseTask;

use App\Module\Admin\Controller\Request\AbstractRequest;
use App\Module\Admin\Controller\Request\Trait\MarketplaceTrait;
use Symfony\Component\HttpFoundation\Request;

final class CreateParseTaskRequest extends AbstractRequest
{
    use MarketplaceTrait;

    public function __construct(
        public readonly string $type,
        public readonly array $params,
        string $marketplace = 'ozon',
        public readonly bool $collectProductData = false,
    ) {
        $this->initMarketplace($marketplace);
    }

    public static function fromRequest(Request $request): static
    {
        $type = $request->request->getString('type');
        $marketplace = $request->request->getString('marketplace', 'ozon');
        $params = [];
        $collectProductData = false;

        if ($type === 'product' || $type === 'reviews') {
            $rawId = $request->request->getString('external_id');
            $params['external_id'] = $rawId !== '' ? (int) $rawId : 0;
            $params['slug'] = $request->request->getString('slug');

            // Извлечение external_id из slug при отсутствии явного ID
            // и обрезка ID из slug, чтобы URL не дублировал: {slug}-{id}-{id}
            if ($params['slug'] !== '' && preg_match('/^(.+)-(\d{5,})$/', $params['slug'], $matches)) {
                if ($params['external_id'] === 0) {
                    $params['external_id'] = (int) $matches[2];
                }
                $params['slug'] = $matches[1];
            }

            if ($type === 'reviews') {
                $collectProductData = $request->request->getBoolean('collect_product_data', false);
                $reviewPeriod = $request->request->getString('review_period', '');
                $dateFrom = $request->request->getString('date_from', '');

                // Временной отрезок приоритетнее кол-ва страниц
                if ($reviewPeriod !== '' && $reviewPeriod !== 'custom') {
                    $params['date_from'] = self::periodToDate($reviewPeriod);
                    $params['max_pages'] = 100;
                } elseif ($dateFrom !== '') {
                    $params['date_from'] = $dateFrom;
                    $params['max_pages'] = 100;
                } else {
                    $params['max_pages'] = $request->request->getInt('max_pages', 10);
                }
            }
        }

        return new static($type, $params, $marketplace, $collectProductData);
    }

    private static function periodToDate(string $period): string
    {
        return match ($period) {
            '1w' => new \DateTimeImmutable('-1 week')->format('Y-m-d'),
            '1m' => new \DateTimeImmutable('-1 month')->format('Y-m-d'),
            '3m' => new \DateTimeImmutable('-3 months')->format('Y-m-d'),
            '6m' => new \DateTimeImmutable('-6 months')->format('Y-m-d'),
            '1y' => new \DateTimeImmutable('-1 year')->format('Y-m-d'),
            default => new \DateTimeImmutable('-1 month')->format('Y-m-d'),
        };
    }
}
