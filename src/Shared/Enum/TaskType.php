<?php

declare(strict_types=1);

namespace App\Shared\Enum;

enum TaskType: string
{
    case PRODUCT = 'product';
    case REVIEWS = 'reviews';
}
