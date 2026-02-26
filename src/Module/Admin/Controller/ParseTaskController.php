<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Module\Admin\Controller\Request\ParseTask\CreateParseTaskRequest;
use App\Module\Admin\Service\BatchTaskService;
use App\Module\Admin\Service\ParseTaskService;
use App\Module\Admin\Service\RedisQueueService;
use App\Shared\Repository\ParseLogRepository;
use App\Shared\Repository\ParseTaskRepository;
use App\Shared\Repository\ProductRepository;
use App\Shared\Repository\ReviewRepository;
use App\Shared\Repository\SolverSessionRepository;
use App\Shared\Repository\TaskRunRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/tasks')]
final class ParseTaskController extends AbstractController
{
    #[Route('/', name: 'task_list')]
    public function list(Request $request, ParseTaskRepository $repo): Response
    {
        $batchId = $request->query->get('batch_id');
        $batchSummary = null;

        if ($batchId !== null && $batchId !== '') {
            $tasks = $repo->findByBatchId($batchId);
            $batchSummary = $repo->getBatchSummary($batchId);
        } else {
            $tasks = $repo->findRecentTasks(100);
            $batchId = null;
        }

        $childTasksMap = [];
        $topLevelTasks = [];
        foreach ($tasks as $task) {
            $parentId = $task->getParentTaskId();
            if ($parentId !== null) {
                $childTasksMap[$parentId][] = $task;
            } else {
                $topLevelTasks[] = $task;
            }
        }
        $grouped = [];
        $batchIds = [];
        foreach ($topLevelTasks as $task) {
            $bid = $task->getBatchId();
            if ($bid !== null && $bid !== '') {
                if (!isset($grouped[$bid])) {
                    $grouped[$bid] = [];
                    $batchIds[] = $bid;
                }
                $grouped[$bid][] = $task;
            } else {
                $grouped['_single_' . $task->getId()] = [$task];
            }
        }

        return $this->render('task/list.html.twig', [
            'tasks' => $topLevelTasks,
            'grouped' => $grouped,
            'batchIds' => $batchIds,
            'batchId' => $batchId,
            'batchSummary' => $batchSummary,
            'childTasksMap' => $childTasksMap,
        ]);
    }

    #[Route('/create', name: 'task_create', methods: ['GET', 'POST'])]
    public function create(Request $request, ParseTaskService $service): Response
    {
        if ($request->isMethod('POST')) {
            $dto = CreateParseTaskRequest::fromRequest($request);
            if ($dto->collectProductData && $dto->type === 'reviews') {
                $task = $service->createReviewsTaskWithProductSubtask($dto->type, $dto->params, $dto->marketplace);
            } else {
                $task = $service->createTask($dto->type, $dto->params, $dto->marketplace);
            }
            $this->addFlash('success', sprintf('Задача %s создана', $task->getId()));

            return $this->redirectToRoute('task_list');
        }

        return $this->render('task/create.html.twig');
    }

    /**
     * Валидация загруженного файла без создания задач.
     * Возвращает JSON с предпросмотром распознанных записей.
     */
    #[Route('/create/validate-file', name: 'task_validate_file', methods: ['POST'])]
    public function validateFile(Request $request, BatchTaskService $batchService): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if ($file === null) {
            return $this->json(['error' => 'Файл не загружен'], Response::HTTP_BAD_REQUEST);
        }

        $result = $batchService->validateFile($file);

        return $this->json($result);
    }

    /**
     * Массовое создание задач из загруженного файла.
     */
    #[Route('/create/batch', name: 'task_create_batch', methods: ['POST'])]
    public function createBatch(Request $request, BatchTaskService $batchService): Response
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if ($file === null) {
            $this->addFlash('error', 'Файл не загружен');

            return $this->redirectToRoute('task_create');
        }

        $type = $request->request->getString('type');

        if ($type === '' || !in_array($type, ['product', 'reviews'], true)) {
            $this->addFlash('error', 'Для массовой загрузки выберите тип: "Парсинг товара" или "Парсинг отзывов"');

            return $this->redirectToRoute('task_create');
        }

        $marketplace = $request->request->getString('marketplace', 'ozon');
        $collectProductData = $request->request->getBoolean('collect_product_data', false);
        $result = $batchService->createTasksFromFile($file, $type, $marketplace, $collectProductData);

        if ($result['created'] > 0) {
            $message = sprintf('Создано задач: %d', $result['created']);

            if ($result['skipped'] > 0) {
                $message .= sprintf('. Пропущено: %d', $result['skipped']);
            }

            $this->addFlash('success', $message);

            return $this->redirectToRoute('task_list', ['batch_id' => $result['batch_id']]);
        }

        $errorDetails = '';

        if (!empty($result['errors'])) {
            $errorDetails = '. ' . $result['errors'][0]['error'];
        }

        $this->addFlash('error', 'Не удалось создать ни одной задачи' . $errorDetails);

        return $this->redirectToRoute('task_list');
    }

    #[Route('/{id}/cancel', name: 'task_cancel', methods: ['POST'])]
    public function cancel(string $id, ParseTaskService $service): Response
    {
        $service->cancelTask($id);
        $this->addFlash('success', 'Задача отменена');

        return $this->redirectToRoute('task_list');
    }

    /**
     * Удаляет задачу и все связанные логи.
     */
    #[Route('/{id}/delete', name: 'task_delete', methods: ['POST'])]
    public function delete(string $id, ParseTaskService $service): Response
    {
        if ($service->deleteTask($id)) {
            $this->addFlash('success', 'Задача и связанные логи удалены');
        } else {
            $this->addFlash('error', 'Задача не найдена');
        }

        return $this->redirectToRoute('task_list');
    }

    /**
     * Массовое удаление задач и связанных логов.
     */
    #[Route('/bulk-delete', name: 'task_bulk_delete', methods: ['POST'])]
    public function bulkDelete(Request $request, ParseTaskService $service): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (!is_array($ids) || empty($ids)) {
            return new JsonResponse(['error' => 'Не указаны задачи для удаления'], Response::HTTP_BAD_REQUEST);
        }

        // Валидация: только UUID-подобные строки
        $ids = array_filter($ids, static fn ($id) => is_string($id) && preg_match('/^[0-9a-f\-]{36}$/i', $id));

        $deleted = $service->deleteTasks($ids);

        return new JsonResponse(['deleted' => $deleted]);
    }

    #[Route('/{id}/progress', name: 'task_progress')]
    public function progress(string $id, RedisQueueService $queueService): Response
    {
        return $this->json($queueService->getProgress($id));
    }

    /**
     * Возвращает логи задачи в формате JSON для модального окна.
     */
    #[Route('/{id}/logs', name: 'task_logs_json')]
    public function logsJson(string $id, Request $request, ParseLogRepository $logRepo, ParseTaskRepository $taskRepo, TaskRunRepository $runRepo): JsonResponse
    {
        $runId = $request->query->get('run_id');

        // Если run_id указан — логи конкретного запуска
        if ($runId !== null && $runId !== '') {
            $logs = $logRepo->findByRunId($runId);
            $run = $runRepo->find($runId);

            $msk = new \DateTimeZone('Europe/Moscow');

            $duration = null;
            $durationFormatted = null;
            if ($run !== null) {
                $startedAt = $run->getStartedAt();
                $finishedAt = $run->getFinishedAt();

                if ($startedAt !== null && $finishedAt !== null) {
                    $duration = $finishedAt->getTimestamp() - $startedAt->getTimestamp();
                    $durationFormatted = $this->formatDuration($duration);
                } elseif ($startedAt !== null) {
                    $duration = time() - $startedAt->getTimestamp();
                    $durationFormatted = $this->formatDuration($duration) . ' (выполняется)';
                }
            }

            $data = array_map(static fn ($log) => [
                'time' => $log->getCreatedAt()->setTimezone($msk)->format('d.m.Y H:i:s'),
                'level' => $log->getLevel(),
                'channel' => $log->getChannel(),
                'traceId' => $log->getTraceId(),
                'message' => $log->getMessage(),
                'context' => $log->getContext(),
            ], $logs);

            return new JsonResponse([
                'logs' => $data,
                'taskMeta' => [
                    'startedAt' => $run?->getStartedAt()?->setTimezone($msk)->format('d.m.Y H:i:s'),
                    'completedAt' => $run?->getFinishedAt()?->setTimezone($msk)->format('d.m.Y H:i:s'),
                    'duration' => $duration,
                    'durationFormatted' => $durationFormatted,
                    'status' => $run?->getStatus(),
                ],
            ]);
        }

        // Фоллбэк: все логи задачи (без фильтрации по запуску)
        $logs = $logRepo->findByTaskId($id);
        $task = $taskRepo->find($id);

        $msk = new \DateTimeZone('Europe/Moscow');

        $duration = null;
        $durationFormatted = null;
        if ($task !== null) {
            $startedAt = $task->getStartedAt();
            $completedAt = $task->getCompletedAt();

            if ($startedAt !== null && $completedAt !== null) {
                $duration = $completedAt->getTimestamp() - $startedAt->getTimestamp();
                $durationFormatted = $this->formatDuration($duration);
            } elseif ($startedAt !== null) {
                $duration = time() - $startedAt->getTimestamp();
                $durationFormatted = $this->formatDuration($duration) . ' (выполняется)';
            }
        }

        $data = array_map(static fn ($log) => [
            'time' => $log->getCreatedAt()->setTimezone($msk)->format('d.m.Y H:i:s'),
            'level' => $log->getLevel(),
            'channel' => $log->getChannel(),
            'traceId' => $log->getTraceId(),
            'message' => $log->getMessage(),
            'context' => $log->getContext(),
        ], $logs);

        return new JsonResponse([
            'logs' => $data,
            'taskMeta' => [
                'startedAt' => $task?->getStartedAt()?->setTimezone($msk)->format('d.m.Y H:i:s'),
                'completedAt' => $task?->getCompletedAt()?->setTimezone($msk)->format('d.m.Y H:i:s'),
                'duration' => $duration,
                'durationFormatted' => $durationFormatted,
                'status' => $task?->getStatus(),
            ],
        ]);
    }

    /**
     * Форматирует длительность в человекочитаемый вид.
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' сек';
        }

        $min = intdiv($seconds, 60);
        $sec = $seconds % 60;

        if ($min < 60) {
            return $min . ' мин ' . $sec . ' сек';
        }

        $hours = intdiv($min, 60);
        $min = $min % 60;

        return $hours . ' ч ' . $min . ' мин ' . $sec . ' сек';
    }

    /**
     * Возвращает результаты парсинга (товары и отзывы) для задачи.
     */
    #[Route('/{id}/results', name: 'task_results_json')]
    public function resultsJson(
        string $id,
        ParseTaskRepository $taskRepo,
        ProductRepository $productRepo,
        ReviewRepository $reviewRepo,
    ): JsonResponse {
        $task = $taskRepo->find($id);

        if ($task === null) {
            return new JsonResponse(['error' => 'Задача не найдена'], Response::HTTP_NOT_FOUND);
        }

        $childIds = $taskRepo->findChildTaskIds($id);
        $allTaskIds = array_merge([$id], $childIds);

        $products = [];
        $reviews = [];
        foreach ($allTaskIds as $taskId) {
            $products = array_merge($products, $productRepo->findByTaskId($taskId));
            $reviews = array_merge($reviews, $reviewRepo->findByTaskId($taskId));
        }

        $productsData = array_map(static fn ($p) => [
            'id' => $p->getId(),
            'externalId' => $p->getExternalId(),
            'title' => $p->getTitle(),
            'url' => $p->getUrl(),
            'price' => $p->getPrice(),
            'originalPrice' => $p->getOriginalPrice(),
            'rating' => $p->getRating(),
            'reviewCount' => $p->getReviewCount(),
            'imageUrl' => $p->getImageUrl(),
            'category' => $p->getCategory()?->getName(),
            'characteristics' => $p->getCharacteristics(),
        ], $products);

        $reviewsData = array_map(static fn ($r) => [
            'id' => $r->getId(),
            'productTitle' => $r->getProduct()->getTitle(),
            'author' => $r->getAuthor(),
            'rating' => $r->getRating(),
            'text' => $r->getText(),
            'pros' => $r->getPros(),
            'cons' => $r->getCons(),
            'reviewDate' => $r->getReviewDate()?->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('d.m.Y'),
            'imageUrls' => $r->getImageUrls(),
            'firstReply' => $r->getFirstReply(),
        ], $reviews);

        return new JsonResponse([
            'taskType' => $task->getType(),
            'parentTaskId' => $task->getParentTaskId(),
            'products' => $productsData,
            'reviews' => $reviewsData,
        ]);
    }

    /**
     * Возвращает сессии solver для задачи с готовыми curl-командами.
     */
    #[Route('/{id}/sessions', name: 'task_sessions_json')]
    public function sessionsJson(string $id, SolverSessionRepository $sessionRepo): JsonResponse
    {
        $sessions = $sessionRepo->findByTaskId($id);

        $msk = new \DateTimeZone('Europe/Moscow');

        $data = array_map(static function ($session) use ($msk) {
            $cookies = $session->getCookies();
            $userAgent = $session->getUserAgent();

            // Формируем curl-команду для воспроизведения запроса
            $cookieString = implode('; ', array_map(
                static fn (array $c) => $c['name'] . '=' . $c['value'],
                $cookies,
            ));

            $curl = "curl 'https://www.ozon.ru/api/entrypoint-api.bx/page/json/v2?url=/product/...'";
            $curl .= " \\\n  -H 'User-Agent: " . $userAgent . "'";
            if ($cookieString !== '') {
                $curl .= " \\\n  -H 'Cookie: " . $cookieString . "'";
            }
            $curl .= " \\\n  -H 'Accept: application/json'";
            $curl .= " \\\n  -H 'Referer: https://www.ozon.ru/'";
            $curl .= " \\\n  -H 'Origin: https://www.ozon.ru'";
            $curl .= " \\\n  -H 'Sec-Fetch-Dest: empty'";
            $curl .= " \\\n  -H 'Sec-Fetch-Mode: cors'";
            $curl .= " \\\n  -H 'Sec-Fetch-Site: same-origin'";

            return [
                'id' => $session->getId(),
                'status' => $session->getStatus(),
                'proxy' => $session->getProxy(),
                'userAgent' => $userAgent,
                'cookiesCount' => count($cookies),
                'errorMessage' => $session->getErrorMessage(),
                'createdAt' => $session->getCreatedAt()->setTimezone($msk)->format('d.m.Y H:i:s'),
                'curl' => $curl,
            ];
        }, $sessions);

        return new JsonResponse($data);
    }

    /**
     * Возвращает список запусков задачи (последние 5).
     */
    #[Route('/{id}/runs', name: 'task_runs_json')]
    public function runsJson(string $id, TaskRunRepository $runRepo): JsonResponse
    {
        $runs = $runRepo->findByTaskId($id, 5);
        $msk = new \DateTimeZone('Europe/Moscow');

        $data = array_map(static fn ($run) => [
            'id' => $run->getId(),
            'runNumber' => $run->getRunNumber(),
            'status' => $run->getStatus(),
            'startedAt' => $run->getStartedAt()?->setTimezone($msk)->format('d.m.Y H:i:s'),
            'finishedAt' => $run->getFinishedAt()?->setTimezone($msk)->format('d.m.Y H:i:s'),
            'parsedItems' => $run->getParsedItems(),
            'error' => $run->getError(),
            'identityId' => $run->getIdentityId() ? substr($run->getIdentityId(), 0, 8) : null,
            'createdAt' => $run->getCreatedAt()->setTimezone($msk)->format('d.m.Y H:i:s'),
        ], $runs);

        return new JsonResponse($data);
    }

    /**
     * Перезапускает задачу (создаёт новый запуск и публикует в очередь).
     */
    #[Route('/{id}/rerun', name: 'task_rerun', methods: ['POST'])]
    public function rerun(string $id, ParseTaskService $service): JsonResponse
    {
        $result = $service->rerunTask($id);

        if ($result === null) {
            return new JsonResponse(['error' => 'Задача не найдена'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Задача перезапущена',
        ]);
    }
}
