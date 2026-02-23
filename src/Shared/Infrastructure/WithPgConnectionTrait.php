<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Module\Parser\Storage\PgConnectionPool;

trait WithPgConnectionTrait
{
    private readonly PgConnectionPool $pool;

    /**
     * @template T
     * @param callable(\PDO): T $operation
     * @return T
     */
    private function withConnection(callable $operation): mixed
    {
        $pdo = $this->pool->get();
        try {
            $result = $operation($pdo);
            $this->pool->put($pdo);
            return $result;
        } catch (\Throwable $exception) {
            $this->pool->put($pdo);
            throw $exception;
        }
    }
}
