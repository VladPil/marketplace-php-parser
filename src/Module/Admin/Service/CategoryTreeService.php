<?php

declare(strict_types=1);

namespace App\Module\Admin\Service;

use App\Shared\Repository\CategoryRepository;

final class CategoryTreeService
{
    public function __construct(
        private readonly CategoryRepository $categoryRepository,
    ) {}

    public function buildTree(): array
    {
        $categories = $this->categoryRepository->findTree();

        $tree = [];
        $map = [];

        foreach ($categories as $category) {
            $node = [
                'id' => $category->getId(),
                'name' => $category->getName(),
                'path' => $category->getPath(),
                'depth' => $category->getDepth(),
                'productCount' => $category->getProductCount(),
                'children' => [],
            ];

            $map[$category->getId()] = $node;

            $parent = $category->getParent();
            if ($parent === null) {
                $tree[] = &$map[$category->getId()];
            } elseif (isset($map[$parent->getId()])) {
                $map[$parent->getId()]['children'][] = &$map[$category->getId()];
            }
        }

        return $tree;
    }
}
