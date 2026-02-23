<?php

declare(strict_types=1);

namespace App\Module\Parser\Queue;

use App\Module\Parser\Config\RedisConfig;
use Swoole\Coroutine\Channel;

final class RedisConnectionPool
{
    private Channel $pool;
    private int $currentSize = 0;

    public function __construct(
        private readonly RedisConfig $config,
    ) {
        $this->pool = new Channel($this->config->poolSize);
    }

    public function get(): \Redis
    {
        if ($this->pool->length() > 0) {
            $redis = $this->pool->pop();
            try {
                $redis->ping();
                return $redis;
            } catch (\Throwable) {
                $this->currentSize--;
            }
        }

        if ($this->currentSize < $this->config->poolSize) {
            return $this->createConnection();
        }

        return $this->pool->pop();
    }

    public function put(\Redis $redis): void
    {
        if ($this->pool->length() < $this->config->poolSize) {
            $this->pool->push($redis);
        } else {
            $this->currentSize--;
        }
    }

    public function close(): void
    {
        $this->pool->close();
    }

    private function createConnection(): \Redis
    {
        $this->currentSize++;
        $redis = new \Redis();
        $redis->connect($this->config->host, $this->config->port);
        if ($this->config->database > 0) {
            $redis->select($this->config->database);
        }
        return $redis;
    }
}
