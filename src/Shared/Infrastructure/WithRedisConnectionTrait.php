<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Module\Parser\Queue\RedisConnectionPool;

trait WithRedisConnectionTrait
{
    private readonly RedisConnectionPool $pool;

    /**
     * @template T
     * @param callable(\Redis): T $operation
     * @return T
     */
    private function withRedis(callable $operation): mixed
    {
        $redis = $this->pool->get();
        try {
            $result = $operation($redis);
            $this->pool->put($redis);
            return $result;
        } catch (\Throwable $e) {
            // Не возвращаем битое соединение в пул
            if ($redis instanceof \Redis && $redis->isConnected()) {
                $this->pool->put($redis);
            } else {
                $this->pool->drop();
            }
            throw $e;
        }
    }
}
