<?php

declare(strict_types=1);

namespace App\Module\Parser\Worker;

use App\Shared\Contract\TaskHandlerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class TaskHandlerRegistry
{
    /** @var TaskHandlerInterface[] */
    private array $handlers = [];

    /**
     * @param iterable<TaskHandlerInterface> $taggedHandlers
     */
    public function __construct(
        #[AutowireIterator('app.task_handler')]
        iterable $taggedHandlers,
    ) {
        foreach ($taggedHandlers as $handler) {
            $this->handlers[] = $handler;
        }
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
