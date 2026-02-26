<?php

declare(strict_types=1);

namespace App\Module\Parser\Marketplace;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class MarketplaceRegistry
{
    /** @var array<string, MarketplaceInterface> */
    private array $marketplaces = [];

    /**
     * @param iterable<MarketplaceInterface> $taggedMarketplaces
     */
    public function __construct(
        #[AutowireIterator('app.marketplace')]
        iterable $taggedMarketplaces,
    ) {
        foreach ($taggedMarketplaces as $marketplace) {
            $this->marketplaces[$marketplace->getName()] = $marketplace;
        }
    }

    public function get(string $name): MarketplaceInterface
    {
        return $this->marketplaces[$name]
            ?? throw new \RuntimeException(sprintf('Неизвестный маркетплейс: %s', $name));
    }

    /** @return string[] */
    public function getAvailable(): array
    {
        return array_keys($this->marketplaces);
    }
}
