<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller\Response;

use App\Shared\Entity\Product;

final class ProductResponseTransformer extends ResponseTransformer
{
    public function transform(object $entity): array
    {
        assert($entity instanceof Product);

        return [
            'id' => $entity->getId(),
            'externalId' => $entity->getExternalId(),
            'marketplace' => $entity->getMarketplace(),
            'title' => $entity->getTitle(),
            'url' => $entity->getUrl(),
            'price' => $entity->getPrice(),
            'originalPrice' => $entity->getOriginalPrice(),
            'rating' => $entity->getRating(),
            'reviewCount' => $entity->getReviewCount(),
            'imageUrl' => $entity->getImageUrl(),
            'category' => $entity->getCategory()?->getName(),
        ];
    }
}
