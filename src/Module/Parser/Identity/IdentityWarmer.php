<?php

declare(strict_types=1);

namespace App\Module\Parser\Identity;

use App\Module\Parser\Proxy\ProxyProvider;
use App\Shared\Logging\ParseLogger;

final class IdentityWarmer
{
    private bool $running = true;

    public function __construct(
        private readonly IdentityPool $pool,
        private readonly IdentityPoolConfig $config,
        private readonly ProxyProvider $proxyProvider,
        private readonly ParseLogger $logger,
    ) {}

    /**
     * Основной цикл: для каждого прокси без ready identity вызывает solver.
     *
     * Запускается как Swoole-корутина внутри ParseRunCommand.
     */
    public function run(): void
    {
        $this->logger->info('[warmer] Запущен');

        // Очистка stale identity (прокси удалены из конфига)
        $this->cleanStaleIdentities();

        while ($this->running) {
            try {
                $this->warmOnce();
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('[warmer] Ошибка итерации: %s', $e->getMessage()));
            }

            if (!$this->running) {
                break;
            }

            // Swoole-совместимый sleep (не блокирует event loop)
            if (class_exists(\Swoole\Coroutine::class)) {
                \Swoole\Coroutine::sleep($this->config->warmerIntervalSeconds);
            } else {
                sleep($this->config->warmerIntervalSeconds);
            }
        }

        $this->logger->info('[warmer] Остановлен');
    }

    public function warmOnce(): void
    {
        $proxies = $this->proxyProvider->getAll();
        if (empty($proxies)) {
            return;
        }

        $allIdentities = $this->pool->getAllIdentities();
        $identityByProxy = [];
        foreach ($allIdentities as $identity) {
            $key = $identity->proxyAddress ?? 'direct';
            $identityByProxy[$key] = ($identityByProxy[$key] ?? 0) + 1;
        }

        $toWarm = [];
        foreach ($proxies as $proxy) {
            $address = $proxy['address'];
            $type = $proxy['type'] ?? 'static';
            $existingCount = $identityByProxy[$address] ?? 0;
            if ($existingCount >= 1) {
                continue;
            }

            $toWarm[] = ['address' => $address, 'type' => $type];
        }

        if (empty($toWarm)) {
            return;
        }

        $this->logger->info(sprintf('[warmer] Прогрев %d identity параллельно...', count($toWarm)));

        $warmed = 0;

        if (class_exists(\Swoole\Coroutine\WaitGroup::class)) {
            $wg = new \Swoole\Coroutine\WaitGroup();

            foreach ($toWarm as $item) {
                $wg->add();
                \Swoole\Coroutine::create(function () use ($wg, $item, &$warmed) {
                    try {
                        $identity = $this->pool->warmIdentity($item['address'], $item['type']);
                        if ($identity !== null) {
                            $warmed++;
                        }
                    } catch (\Throwable $e) {
                        $this->logger->error(sprintf(
                            '[warmer] Ошибка прогрева %s: %s',
                            $item['address'] ?? 'direct',
                            $e->getMessage(),
                        ));
                    } finally {
                        $wg->done();
                    }
                });
            }

            $wg->wait(180.0);
        } else {
            foreach ($toWarm as $item) {
                $identity = $this->pool->warmIdentity($item['address'], $item['type']);
                if ($identity !== null) {
                    $warmed++;
                }
            }
        }
        if ($warmed > 0) {
            $stats = $this->pool->getStats();
            $this->logger->info(sprintf(
                '[warmer] Прогрето %d identity (пул: ready=%d, active=%d, quarantine=%d)',
                $warmed,
                $stats['ready'],
                $stats['active'],
                $stats['quarantine'],
            ));
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Удаляет identity, чьи прокси больше не в конфигурации.
     *
     * Вызывается при старте warmer, чтобы убрать stale identity из Redis.
     */
    private function cleanStaleIdentities(): void
    {
        $allIdentities = $this->pool->getAllIdentities();
        if (empty($allIdentities)) {
            return;
        }

        $configuredProxies = [];
        foreach ($this->proxyProvider->getAll() as $proxy) {
            $configuredProxies[$proxy['address']] = true;
        }

        $cleaned = 0;
        foreach ($allIdentities as $identity) {
            if ($identity->proxyAddress !== null && !isset($configuredProxies[$identity->proxyAddress])) {
                $this->pool->deleteIdentity($identity);
                $this->logger->info(sprintf(
                    '[warmer] Удалена stale identity %s (прокси %s больше не в конфиге)',
                    substr($identity->id, 0, 8),
                    $identity->maskedProxy(),
                ));
                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            $this->logger->info(sprintf('[warmer] Очищено %d stale identity', $cleaned));
        }
    }
}
