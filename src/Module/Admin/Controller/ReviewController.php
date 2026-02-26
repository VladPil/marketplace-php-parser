<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Shared\Repository\ReviewRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/reviews')]
final class ReviewController extends AbstractController
{
    #[Route('/{productId}', name: 'review_list')]
    public function list(int $productId, ReviewRepository $repo): Response
    {
        $reviews = $repo->findByProduct($productId);

        // Берём parseTaskId из первого отзыва (все отзывы одного товара обычно из одной задачи)
        $parseTaskId = null;
        foreach ($reviews as $review) {
            if ($review->getParseTaskId() !== null) {
                $parseTaskId = $review->getParseTaskId();
                break;
            }
        }

        return $this->render('review/list.html.twig', [
            'reviews' => $reviews,
            'productId' => $productId,
            'parseTaskId' => $parseTaskId,
        ]);
    }
}
