<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Module\Admin\Service\SolverHealthChecker;
use App\Module\Admin\Service\IdentityPoolStats;
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
    public function index(SolverHealthChecker $solverChecker, IdentityPoolStats $identityStats): Response
    {
        $solver = $solverChecker->check();
        $identityPool = $identityStats->getStats();
        return $this->render('health/index.html.twig', [
            'solver' => $solver,
            'identity_pool' => $identityPool,
        ]);
    }

    /**
     * API-эндпоинт для AJAX-проверки здоровья.
     */
    #[Route('/api', name: 'health_api')]
    public function api(SolverHealthChecker $solverChecker, IdentityPoolStats $identityStats): Response
    {
        $solver = $solverChecker->check();
        $identityPool = $identityStats->getStats();
        return $this->json([
            'solver' => $solver,
            'identity_pool' => $identityPool,
            'status' => $solver['available'] ? 'ok' : 'degraded',
        ]);
    }
}
