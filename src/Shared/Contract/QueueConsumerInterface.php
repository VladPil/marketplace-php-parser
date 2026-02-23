<?php

declare(strict_types=1);

namespace App\Shared\Contract;

/**
 * Контракт для потребления задач из очереди.
 *
 * Реализации должны поддерживать блокирующее чтение
 * с таймаутом и распределённые блокировки задач.
 */
interface QueueConsumerInterface
{
    /**
     * Потребляет следующую задачу из очереди.
     *
     * @param float $timeout Максимальное время ожидания в секундах
     * @return array|null Данные задачи или null при таймауте
     */
    public function consume(float $timeout = 5.0): ?array;

    /**
     * Устанавливает распределённую блокировку на задачу.
     *
     * Предотвращает параллельную обработку одной и той же задачи
     * несколькими воркерами.
     *
     * @param string $taskId Идентификатор задачи
     * @param int    $ttl    Время жизни блокировки в секундах
     * @return bool true, если блокировка успешно установлена
     */
    public function acquireLock(string $taskId, int $ttl = 300): bool;

    /**
     * Снимает распределённую блокировку с задачи.
     *
     * @param string $taskId Идентификатор задачи
     * @return void
     */
    public function releaseLock(string $taskId): void;
}
