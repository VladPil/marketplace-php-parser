<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller\Response;

use App\Shared\Entity\ParseTask;

final class ParseTaskResponseTransformer extends ResponseTransformer
{
    public function transform(object $entity): array
    {
        assert($entity instanceof ParseTask);

        return [
            'id' => $entity->getId(),
            'type' => $entity->getType(),
            'status' => $entity->getStatus(),
            'marketplace' => $entity->getMarketplace(),
            'params' => $entity->getParams(),
            'totalItems' => $entity->getTotalItems(),
            'parsedItems' => $entity->getParsedItems(),
            'errorMessage' => $entity->getErrorMessage(),
            'createdAt' => $entity->getCreatedAt()->format('Y-m-d H:i:s'),
            'updatedAt' => $entity->getUpdatedAt()->format('Y-m-d H:i:s'),
            'startedAt' => $entity->getStartedAt()?->format('Y-m-d H:i:s'),
            'completedAt' => $entity->getCompletedAt()?->format('Y-m-d H:i:s'),
        ];
    }
}
