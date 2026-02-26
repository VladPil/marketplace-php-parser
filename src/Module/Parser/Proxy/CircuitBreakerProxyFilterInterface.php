<?php

declare(strict_types=1);

namespace App\Module\Parser\Proxy;

interface CircuitBreakerProxyFilterInterface
{
    public function isAvailable(string $proxyId): bool;

    public function recordSuccess(string $proxyId): void;

    public function recordFailure(string $proxyId): void;
}