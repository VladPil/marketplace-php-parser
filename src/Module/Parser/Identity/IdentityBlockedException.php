<?php

declare(strict_types=1);

namespace App\Module\Parser\Identity;

/**
 * Исключение при блокировке identity (HTTP 403 или отказ browser fetch).
 *
 * Сигнализирует ProductTaskHandler о необходимости карантина текущей identity
 * и перезахвата свежей из пула.
 */
final class IdentityBlockedException extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 403, $previous);
    }
}
