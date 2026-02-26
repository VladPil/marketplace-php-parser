<?php

declare(strict_types=1);

namespace App\Shared\DTO;

/**
 * Результат выполнения задачи — определяет итоговый подстатус.
 */
final readonly class TaskResult
{
    public function __construct(
        public int $parsedItems,
        public int $errorCount = 0,
        /** Задача пропущена (например, товар уже заполнен) */
        public bool $skipped = false,
    ) {}

    /**
     * Определяет подстатус задачи по результатам.
     */
    public function resolveStatus(): string
    {
        if ($this->skipped) {
            return 'completed_skipped';
        }

        if ($this->parsedItems === 0) {
            return 'completed_empty';
        }

        if ($this->errorCount > 0) {
            return 'completed_partial';
        }

        return 'completed_success';
    }
}
