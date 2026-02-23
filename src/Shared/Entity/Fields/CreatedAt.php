<?php

declare(strict_types=1);

namespace App\Shared\Entity\Fields;

use Doctrine\ORM\Mapping as ORM;

trait CreatedAt
{
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function initCreatedAt(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
