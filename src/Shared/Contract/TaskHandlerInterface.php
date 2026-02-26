<?php

declare(strict_types=1);

namespace App\Shared\Contract;

use App\Shared\DTO\TaskResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Контракт для обработчика задач.
 *
 * Каждая реализация отвечает за обработку определённого типа задач,
 * полученных из очереди.
 */
#[AutoconfigureTag('app.task_handler')]
interface TaskHandlerInterface
{
    /**
     * Обрабатывает задачу с указанными параметрами.
     *
     * @param string $taskId Идентификатор задачи
     * @param array  $params Параметры задачи
     * @return TaskResult Результат с количеством обработанных элементов и ошибок
     */
    public function handle(string $taskId, array $params): TaskResult;

    /**
     * Проверяет, поддерживает ли обработчик данный тип задачи.
     *
     * @param string $taskType Тип задачи
     * @return bool true, если обработчик может обработать данный тип
     */
    public function supports(string $taskType): bool;
}
