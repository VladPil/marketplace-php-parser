<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Shared\Repository\ParseLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Контроллер для просмотра логов парсинга в админке.
 *
 * Предоставляет интерфейс для просмотра детальных логов
 * с фильтрацией по задаче, trace_id и уровню.
 */
#[Route('/logs')]
final class LogController extends AbstractController
{
    /**
     * Отображает список последних логов с фильтрацией.
     */
    #[Route('/', name: 'log_list')]
    public function list(Request $request, ParseLogRepository $repo): Response
    {
        $level = $request->query->get('level');
        $logs = $repo->findRecent(200, $level);

        return $this->render('log/list.html.twig', [
            'logs' => $logs,
            'currentLevel' => $level,
        ]);
    }

    /**
     * Отображает логи конкретной задачи парсинга.
     */
    #[Route('/task/{taskId}', name: 'log_by_task')]
    public function byTask(string $taskId, ParseLogRepository $repo): Response
    {
        $logs = $repo->findByTaskId($taskId);

        return $this->render('log/task.html.twig', [
            'logs' => $logs,
            'taskId' => $taskId,
        ]);
    }

    /**
     * Отображает логи по trace_id для сквозной трассировки.
     */
    #[Route('/trace/{traceId}', name: 'log_by_trace')]
    public function byTrace(string $traceId, ParseLogRepository $repo): Response
    {
        $logs = $repo->findByTraceId($traceId);

        return $this->render('log/trace.html.twig', [
            'logs' => $logs,
            'traceId' => $traceId,
        ]);
    }
}
