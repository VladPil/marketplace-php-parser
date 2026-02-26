<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller\Response;

abstract class ResponseTransformer
{
    abstract public function transform(object $entity): array;

    public function transformCollection(iterable $entities): array
    {
        $result = [];
        foreach ($entities as $entity) {
            $result[] = $this->transform($entity);
        }
        return $result;
    }
}
