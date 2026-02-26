<?php

declare(strict_types=1);

namespace App\Shared\Enum;

enum TaskStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case COMPLETED_SUCCESS = 'completed_success';
    case COMPLETED_EMPTY = 'completed_empty';
    case COMPLETED_PARTIAL = 'completed_partial';
    case COMPLETED_SKIPPED = 'completed_skipped';
    case FAILED = 'failed';
    case PAUSED = 'paused';
    case CANCELLED = 'cancelled';
}
