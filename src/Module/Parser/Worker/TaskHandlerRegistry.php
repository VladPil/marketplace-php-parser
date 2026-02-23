<?php

declare(strict_types=1);

namespace App\Module\Parser\Worker;

use App\Shared\Contract\TaskHandlerInterface;

final class TaskHandlerRegistry
{
    /** @var TaskHandlerInterface[] */
    private array $handlers = [];

    public function register(TaskHandlerInterface $handler): void
    {
        $this->handlers[] = $handler;
    }

    public function getHandler(string $taskType): TaskHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($taskType)) {
                return $handler;
            }
        }

        throw new \RuntimeException(sprintf('Обработчик для типа задачи не найден: %s', $taskType));
    }
}
