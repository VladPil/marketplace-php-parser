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
     * Основной цикл: прогрев новых identity и проактивное обновление устаревших.
     *
     * Для каждого прокси без identity вызывает solver для создания новой.
     * Для прокси с identity, прожившей > 75% TTL, создаёт замену до истечения —
     * чтобы пул не пустел при простое.
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

        // Группируем identity по прокси (не просто считаем, а храним объекты для проверки TTL)
        $identitiesByProxy = [];
        foreach ($allIdentities as $identity) {
            $key = $identity->proxyAddress ?? 'direct';
            $identitiesByProxy[$key][] = $identity;
        }

        $renewalThreshold = $this->config->identityTtlSeconds * 0.75;
        $toWarm = [];
        $toCleanAfterWarm = []; // address => Identity[] — устаревшие identity для удаления после успешного прогрева

        foreach ($proxies as $proxy) {
            $address = $proxy['address'];
            $type = $proxy['type'] ?? 'static';
            $proxyIdentities = $identitiesByProxy[$address] ?? [];

            if (empty($proxyIdentities)) {
                // Нет identity — нужен прогрев (базовая логика)
                $toWarm[] = ['address' => $address, 'type' => $type];
                continue;
            }

            // Проверяем, есть ли хотя бы одна свежая identity (моложе 75% TTL)
            $hasFresh = false;
            $expiring = [];
            $now = microtime(true);

            foreach ($proxyIdentities as $identity) {
                $age = $now - $identity->createdAt;
                if ($age < $renewalThreshold) {
                    $hasFresh = true;
                } else {
                    $expiring[] = $identity;
                }
            }

            // Если все identity устаревают — запланировать проактивное обновление
            if (!$hasFresh && !empty($expiring)) {
                $toWarm[] = ['address' => $address, 'type' => $type];
                $toCleanAfterWarm[$address] = $expiring;
                $this->logger->info(sprintf(
                    '[warmer] Identity для %s устаревает (возраст > %dс из %dс TTL), запланировано обновление',
                    $expiring[0]->maskedProxy(),
                    (int) $renewalThreshold,
                    $this->config->identityTtlSeconds,
                ));
            }
        }

        if (empty($toWarm)) {
            return;
        }

        $this->logger->info(sprintf('[warmer] Прогрев %d identity параллельно...', count($toWarm)));

        $warmed = 0;
        $warmedAddresses = []; // Адреса с успешным прогревом — для последующей очистки устаревших

        if (class_exists(\Swoole\Coroutine\WaitGroup::class)) {
            $wg = new \Swoole\Coroutine\WaitGroup();

            foreach ($toWarm as $item) {
                $wg->add();
                \Swoole\Coroutine::create(function () use ($wg, $item, &$warmed, &$warmedAddresses) {
                    try {
                        $identity = $this->pool->warmIdentity($item['address'], $item['type']);
                        if ($identity !== null) {
                            $warmed++;
                            $warmedAddresses[] = $item['address'];
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
                    $warmedAddresses[] = $item['address'];
                }
            }
        }

        // Удаляем устаревшие identity после успешного прогрева новых
        $cleaned = 0;
        foreach ($warmedAddresses as $address) {
            if (!isset($toCleanAfterWarm[$address])) {
                continue;
            }
            foreach ($toCleanAfterWarm[$address] as $oldIdentity) {
                $this->pool->deleteIdentity($oldIdentity);
                $this->logger->debug(sprintf(
                    '[warmer] Удалена устаревшая identity %s (прокси: %s, возраст: %dс)',
                    substr($oldIdentity->id, 0, 8),
                    $oldIdentity->maskedProxy(),
                    (int) (microtime(true) - $oldIdentity->createdAt),
                ));
                $cleaned++;
            }
        }

        if ($warmed > 0 || $cleaned > 0) {
            $stats = $this->pool->getStats();
            $this->logger->info(sprintf(
                '[warmer] Прогрето %d identity, очищено %d устаревших (пул: ready=%d, active=%d, quarantine=%d)',
                $warmed,
                $cleaned,
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
