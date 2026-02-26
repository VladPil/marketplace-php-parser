<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Entity\Fields\CreatedAt;
use App\Shared\Entity\Fields\Id;
use App\Shared\Entity\Fields\UpdatedAt;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class BaseEntity
{
    use Id;
    use CreatedAt;
    use UpdatedAt;

    public function __construct()
    {
        $this->initCreatedAt();
        $this->initUpdatedAt();
    }
}
