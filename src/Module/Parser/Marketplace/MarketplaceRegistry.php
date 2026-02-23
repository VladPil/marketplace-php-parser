<?php

declare(strict_types=1);

namespace App\Module\Parser\Marketplace;

final class MarketplaceRegistry
{
    /** @var array<string, MarketplaceInterface> */
    private array $marketplaces = [];

    public function register(MarketplaceInterface $marketplace): void
    {
        $this->marketplaces[$marketplace->getName()] = $marketplace;
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
