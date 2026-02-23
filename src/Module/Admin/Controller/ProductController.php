<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Shared\Repository\ImageRepository;
use App\Shared\Repository\ProductRepository;
use App\Shared\Repository\ReviewRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/products')]
final class ProductController extends AbstractController
{
    #[Route('/', name: 'product_list')]
    public function list(ProductRepository $repo): Response
    {
        return $this->render('product/list.html.twig', [
            'products' => $repo->findRecent(100),
        ]);
    }

    #[Route('/{id}', name: 'product_show')]
    public function show(
        int $id,
        ProductRepository $productRepo,
        ReviewRepository $reviewRepo,
        ImageRepository $imageRepo,
    ): Response {
        $product = $productRepo->find($id);
        if ($product === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('product/show.html.twig', [
            'product' => $product,
            'reviews' => $reviewRepo->findByProduct($id),
            'images' => $imageRepo->findByEntity('product', $id),
        ]);
    }
}
