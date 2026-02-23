<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Module\Admin\Service\CategoryTreeService;
use App\Shared\Repository\CategoryRepository;
use App\Shared\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/categories')]
final class CategoryController extends AbstractController
{
    #[Route('/', name: 'category_list')]
    public function list(CategoryTreeService $treeService): Response
    {
        return $this->render('category/list.html.twig', [
            'tree' => $treeService->buildTree(),
        ]);
    }

    #[Route('/{id}', name: 'category_show')]
    public function show(int $id, CategoryRepository $categoryRepo, ProductRepository $productRepo): Response
    {
        $category = $categoryRepo->find($id);
        if ($category === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('category/show.html.twig', [
            'category' => $category,
            'products' => $productRepo->findByCategory($id),
        ]);
    }
}
