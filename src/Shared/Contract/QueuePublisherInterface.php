<?php

declare(strict_types=1);

namespace App\Shared\Contract;

/**
 * Контракт для публикации задач в очередь.
 *
 * Отвечает только за отправку задач — в соответствии с ISP
 * (Interface Segregation Principle). Для прогресса и дедупликации
 * используйте ProgressTrackerInterface и DeduplicatorInterface.
 */
interface QueuePublisherInterface
{
    /**
     * Возвращает задачу в очередь для повторной обработки.
     *
     * @param array $task Данные задачи
     */
    public function requeueTask(array $task): void;
}
