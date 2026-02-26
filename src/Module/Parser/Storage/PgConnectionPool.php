<?php

declare(strict_types=1);

namespace App\Module\Parser\Storage;

use App\Module\Parser\Config\DatabaseConfig;
use Swoole\Coroutine\Channel;

final class PgConnectionPool
{
    private Channel $pool;
    private int $currentSize = 0;

    public function __construct(
        private readonly DatabaseConfig $config,
    ) {
        $this->pool = new Channel($this->config->poolSize);
    }

    public function get(): \PDO
    {
        if ($this->pool->length() > 0) {
            $pdo = $this->pool->pop();
            try {
                $pdo->query('SELECT 1');
                return $pdo;
            } catch (\Throwable) {
                $this->currentSize--;
            }
        }

        if ($this->currentSize < $this->config->poolSize) {
            return $this->createConnection();
        }

        return $this->pool->pop();
    }

    public function put(\PDO $pdo): void
    {
        if ($this->pool->length() < $this->config->poolSize) {
            $this->pool->push($pdo);
        } else {
            $this->currentSize--;
        }
    }

    public function close(): void
    {
        $this->pool->close();
    }

    private function createConnection(): \PDO
    {
        $this->currentSize++;
        return new \PDO(
            $this->config->getDsn(),
            $this->config->user,
            $this->config->password,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }
}
