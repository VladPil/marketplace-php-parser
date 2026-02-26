<?php

declare(strict_types=1);

namespace App\Shared\Entity\Fields;

use Doctrine\ORM\Mapping as ORM;

trait Id
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
