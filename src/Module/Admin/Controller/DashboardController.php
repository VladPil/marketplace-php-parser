<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Shared\Repository\CategoryRepository;
use App\Shared\Repository\ParseTaskRepository;
use App\Shared\Repository\ProductRepository;
use App\Shared\Repository\ReviewRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard')]
    public function index(
        ParseTaskRepository $taskRepo,
        ProductRepository $productRepo,
        ReviewRepository $reviewRepo,
        CategoryRepository $categoryRepo,
    ): Response {
        return $this->render('dashboard/index.html.twig', [
            'recentTasks' => $taskRepo->findRecentTasks(10),
            'recentProducts' => $productRepo->findRecent(10),
            'tasksByStatus' => $taskRepo->countByStatus(),
            'tasksByType' => $taskRepo->countByType(),
            'productStats' => $productRepo->getStats(),
            'reviewStats' => $reviewRepo->getStats(),
            'categoryCount' => $categoryRepo->countAll(),
        ]);
    }
}
