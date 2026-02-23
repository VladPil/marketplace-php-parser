<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller\Request\Trait;

trait MarketplaceTrait
{
    public string $marketplace = 'ozon';

    private function initMarketplace(string $marketplace = 'ozon'): void
    {
        $this->marketplace = $marketplace;
    }
}
