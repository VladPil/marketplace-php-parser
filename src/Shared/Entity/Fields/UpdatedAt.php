<?php

declare(strict_types=1);

namespace App\Shared\Entity\Fields;

use Doctrine\ORM\Mapping as ORM;

trait UpdatedAt
{
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    private function initUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
