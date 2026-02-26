<?php

declare(strict_types=1);

namespace App\Shared\Entity\Fields;

use Doctrine\ORM\Mapping as ORM;

trait Marketplace
{
    #[ORM\Column(length: 50)]
    private string $marketplace = 'ozon';

    public function getMarketplace(): string
    {
        return $this->marketplace;
    }

    public function setMarketplace(string $marketplace): self
    {
        $this->marketplace = $marketplace;
        return $this;
    }
}
