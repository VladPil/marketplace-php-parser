<?php

declare(strict_types=1);

namespace App\Shared\Contract;

interface TaskStorageInterface
{
    /**
     * Обновляет статус задачи.
     *
     * @param string      $taskId       Идентификатор задачи
     * @param string      $status       Новый статус
     * @param string|null $errorMessage Сообщение об ошибке
     */
    public function updateTaskStatus(string $taskId, string $status, ?string $errorMessage = null): void;

    /**
     * Обновляет прогресс выполнения задачи.
     *
     * @param string $taskId Идентификатор задачи
     * @param int    $parsed Количество обработанных элементов
     * @param int    $total  Общее количество элементов
     */
    public function updateTaskProgress(string $taskId, int $parsed, int $total): void;

    /**
     * Сохраняет состояние задачи для возобновления.
     *
     * @param string $taskId Идентификатор задачи
     * @param array  $state  Данные состояния
     */
    public function saveResumeState(string $taskId, array $state): void;

    /**
     * Возвращает список задач, которые можно возобновить.
     *
     * @return array Массив задач со статусом 'paused'
     */
    public function getResumableTasks(): array;

    /**
     * Возвращает подзадачи указанной родительской задачи.
     *
     * @param string $parentTaskId Идентификатор родительской задачи
     * @return array Массив подзадач с полями id, type, status, params, marketplace
     */
    public function getChildTasks(string $parentTaskId): array;

    /**
     * Проверяет, завершены ли все подзадачи родительской задачи.
     *
     * @param string $parentTaskId Идентификатор родительской задачи
     * @return bool true если все подзадачи завершены и есть хотя бы одна
     */
    public function areAllChildrenCompleted(string $parentTaskId): bool;

    /**
     * Возвращает ID родительской задачи для указанной подзадачи.
     *
     * @param string $taskId Идентификатор подзадачи
     * @return string|null ID родителя или null если задача не является подзадачей
     */
    public function getParentTaskId(string $taskId): ?string;

    /**
     * Возвращает данные родительской задачи в формате сообщения очереди.
     *
     * @param string $parentTaskId Идентификатор родительской задачи
     * @return array|null Данные задачи {id, type, params, marketplace} или null
     */
    public function getParentTaskAsQueueMessage(string $parentTaskId): ?array;

    public function taskExists(string $taskId): bool;
}
