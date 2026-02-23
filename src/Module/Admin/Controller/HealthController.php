<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Module\Admin\Service\SolverHealthChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Контроллер проверки здоровья всех сервисов системы.
 *
 * Отображает в админке статус всех зависимостей:
 * PostgreSQL, Redis, solver-service.
 */
#[Route('/health')]
final class HealthController extends AbstractController
{
    /**
     * Отображает страницу здоровья всех сервисов.
     */
    #[Route('/', name: 'health_dashboard')]
    public function index(SolverHealthChecker $solverChecker): Response
    {
        $solver = $solverChecker->check();

        return $this->render('health/index.html.twig', [
            'solver' => $solver,
        ]);
    }

    /**
     * API-эндпоинт для AJAX-проверки здоровья.
     */
    #[Route('/api', name: 'health_api')]
    public function api(SolverHealthChecker $solverChecker): Response
    {
        $solver = $solverChecker->check();

        return $this->json([
            'solver' => $solver,
            'status' => $solver['available'] ? 'ok' : 'degraded',
        ]);
    }
}
