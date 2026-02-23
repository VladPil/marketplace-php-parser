<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller\Response;

use App\Shared\Entity\Category;

final class CategoryResponseTransformer extends ResponseTransformer
{
    public function transform(object $entity): array
    {
        assert($entity instanceof Category);

        return [
            'id' => $entity->getId(),
            'externalId' => $entity->getExternalId(),
            'marketplace' => $entity->getMarketplace(),
            'name' => $entity->getName(),
            'depth' => $entity->getDepth(),
            'path' => $entity->getPath(),
            'productCount' => $entity->getProductCount(),
        ];
    }
}
