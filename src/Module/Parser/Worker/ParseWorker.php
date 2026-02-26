<?php

declare(strict_types=1);

namespace App\Module\Parser\Worker;

use App\Module\Parser\Config\ParserConfig;
use App\Shared\Contract\TaskHandlerInterface;
use App\Shared\Contract\QueueConsumerInterface;
use App\Shared\Contract\TaskStorageInterface;
use App\Shared\Contract\QueuePublisherInterface;
use App\Shared\Logging\ParseLogger;
use App\Shared\DTO\TaskResult;
use App\Shared\Tracing\TraceContext;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * Основной воркер парсинга.
 *
 * Потребляет задачи из Redis-очереди, устанавливает trace_id
 * для каждой задачи и делегирует обработку соответствующему handler-у.
 * Логирует все этапы выполнения в базу данных.
 *
 * Каждая задача выполняется в отдельной корутине с жёстким таймаутом
 * (maxTaskTimeoutSeconds). При превышении корутина отменяется,
 * задача помечается как failed — воркер продолжает работу.
 */
final class ParseWorker
{
    private bool $running = true;

    public function __construct(
        private readonly QueueConsumerInterface $consumer,
        private readonly TaskStorageInterface $taskStorage,
        private readonly QueuePublisherInterface $queuePublisher,
        private readonly TaskHandlerRegistry $handlerRegistry,
        private readonly ParseLogger $logger,
        private readonly ParserConfig $config,
    ) {}

    /**
     * Запускает бесконечный цикл потребления задач из очереди.
     *
     * Для каждой задачи генерируется уникальный trace_id,
     * который сохраняется во всех логах и передаётся в solver-service.
     */
    public function run(): void
    {
        $this->logger->info('Воркер парсинга запущен');

        while ($this->running) {
            $task = $this->consumer->consume(5.0);

            if ($task === null) {
                continue;
            }

            $taskId = $task['id'] ?? null;
            $taskType = $task['type'] ?? null;

            if ($taskId === null || $taskType === null) {
                $this->logger->warning('Некорректная задача: отсутствует id или type', ['task' => $task]);
                continue;
            }

            // Устанавливаем контекст трассировки для всей цепочки обработки
            TraceContext::generate();
            TraceContext::setTaskId($taskId);

            $this->logger->info(
                sprintf('Получена задача: type=%s', $taskType),
                ['task_id' => $taskId, 'type' => $taskType, 'params' => $task['params'] ?? []],
            );

            if (!$this->consumer->acquireLock($taskId)) {
                $this->logger->warning(
                    sprintf('Задача %s уже заблокирована, пропускаем', substr($taskId, 0, 8)),
                );
                TraceContext::reset();
                continue;
            }

            if (!$this->taskStorage->taskExists($taskId)) {
                $this->logger->warning(
                    sprintf('Задача %s не найдена в БД (удалена?), пропускаем', substr($taskId, 0, 8)),
                );
                $this->consumer->releaseLock($taskId);
                TraceContext::reset();
                continue;
            }

            try {
                $this->taskStorage->updateTaskStatus($taskId, 'running');
                $handler = $this->handlerRegistry->getHandler($taskType);

                $this->logger->info(sprintf('Запуск обработчика: %s', get_class($handler)));

                $result = $this->executeWithTimeout($handler, $taskId, $task['params'] ?? []);

                $status = $result->resolveStatus();
                $this->taskStorage->updateTaskProgress($taskId, $result->parsedItems, $result->parsedItems);
                $this->taskStorage->updateTaskStatus($taskId, $status);
                $this->logger->info(sprintf('Задача завершена: %s (элементов: %d, ошибок: %d)', $status, $result->parsedItems, $result->errorCount));

                $this->handleParentActivation($taskId, $status);
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Ошибка выполнения задачи: %s', $e->getMessage()),
                    ['exception' => $e::class, 'file' => $e->getFile(), 'line' => $e->getLine()],
                );
                $this->taskStorage->updateTaskStatus($taskId, 'failed', $e->getMessage());

                $this->handleParentActivation($taskId, 'failed');
            } finally {
                $this->consumer->releaseLock($taskId);
                TraceContext::reset();
            }
        }
    }

    /**
     * Останавливает воркер (graceful shutdown).
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Выполняет обработчик задачи в отдельной корутине с жёстким таймаутом.
     *
     * Паттерн: Channel + sub-coroutine + Coroutine::cancel().
     * Если handler зависает (сеть, DNS, Swoole hook) — корутина отменяется,
     * воркер не блокируется и переходит к следующей задаче.
     *
     * @throws \RuntimeException При превышении таймаута
     */
    private function executeWithTimeout(TaskHandlerInterface $handler, string $taskId, array $params): TaskResult
    {
        $timeout = $this->config->maxTaskTimeoutSeconds;
        $channel = new Channel(1);

        $cid = Coroutine::create(function () use ($channel, $handler, $taskId, $params): void {
            TraceContext::inheritFromParent();
            try {
                $result = $handler->handle($taskId, $params);
                $channel->push(['result' => $result]);
            } catch (\Throwable $e) {
                $channel->push(['error' => $e]);
            }
        });

        if ($cid === false) {
            // Не удалось создать корутину — выполняем синхронно (fallback)
            $this->logger->warning('Не удалось создать корутину для задачи, выполнение синхронно');
            return $handler->handle($taskId, $params);
        }

        /** @var array{result?: TaskResult, error?: \Throwable}|false $response */
        $response = $channel->pop((float) $timeout);

        if ($response === false) {
            // Таймаут — корутина зависла, отменяем
            $this->logger->error(
                sprintf('Задача превысила таймаут %d сек — корутина отменена', $timeout),
            );
            Coroutine::cancel($cid);
            throw new \RuntimeException(
                sprintf('Задача превысила максимальное время выполнения (%d сек)', $timeout),
            );
        }

        if (isset($response['error'])) {
            throw $response['error'];
        }

        return $response['result'];
    }

    /**
     * Проверяет наличие родительской задачи и активирует её при завершении всех подзадач.
     */
    private function handleParentActivation(string $taskId, string $childStatus): void
    {
        $parentTaskId = $this->taskStorage->getParentTaskId($taskId);

        if ($parentTaskId === null) {
            return;
        }

        if (in_array($childStatus, ['failed'], true)) {
            $this->taskStorage->updateTaskStatus($parentTaskId, 'failed', 'Подзадача сбора товара завершилась с ошибкой');
            $this->logger->warning(sprintf('Родительская задача %s отмечена как failed из-за ошибки подзадачи', substr($parentTaskId, 0, 8)));
            return;
        }

        if (!$this->taskStorage->areAllChildrenCompleted($parentTaskId)) {
            return;
        }

        $parentTask = $this->taskStorage->getParentTaskAsQueueMessage($parentTaskId);

        if ($parentTask === null) {
            $this->logger->error(sprintf('Родительская задача %s не найдена в БД', substr($parentTaskId, 0, 8)));
            return;
        }

        $this->queuePublisher->requeueTask([
            'id' => $parentTask['id'],
            'type' => $parentTask['type'],
            'params' => array_merge($parentTask['params'] ?? [], ['marketplace' => $parentTask['marketplace']]),
            'marketplace' => $parentTask['marketplace'],
        ]);

        $this->logger->info(sprintf('Родительская задача %s опубликована в очередь', substr($parentTaskId, 0, 8)));
    }
}
