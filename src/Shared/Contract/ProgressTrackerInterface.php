<?php

declare(strict_types=1);

namespace App\Shared\Contract;

/**
 * Контракт для отслеживания прогресса выполнения задач.
 *
 * Выделен из QueuePublisherInterface в соответствии с ISP —
 * модули, которым нужен только прогресс, не зависят от очереди.
 */
interface ProgressTrackerInterface
{
    /**
     * Обновляет прогресс выполнения задачи.
     *
     * @param string $taskId Идентификатор задачи
     * @param int $parsed Количество обработанных элементов
     * @param int $total Общее количество элементов
     * @param string $status Текущий статус (running, completed, failed)
     */
    public function updateProgress(string $taskId, int $parsed, int $total, string $status): void;

    /**
     * Возвращает текущий прогресс задачи.
     *
     * @param string $taskId Идентификатор задачи
     * @return array{parsed: int, total: int, status: string, updated_at: int}
     */
    public function getProgress(string $taskId): array;
}
