<?php

declare(strict_types=1);

namespace App\Module\Parser\Identity;

use App\Module\Parser\Proxy\ProxyHealthTrackerInterface;
use App\Module\Parser\Proxy\ProxyProvider;
use App\Shared\Logging\ParseLogger;

final class IdentitySanitizer
{
    private bool $running = true;

    public function __construct(
        private readonly IdentityPool $pool,
        private readonly IdentityPoolConfig $config,
        private readonly ProxyHealthTrackerInterface $healthTracker,
        private readonly ProxyProvider $proxyProvider,
        private readonly ParseLogger $logger,
    ) {}

    /**
     * Основной цикл: берёт identity из карантина, сбрасывает cookies,
     * тестирует прокси через solver, восстанавливает или удаляет.
     */
    public function run(): void
    {
        $this->logger->info('[sanitizer] Запущен');

        while ($this->running) {
            try {
                $processed = $this->processOnce();
                if (!$processed) {
                    $this->sleepInterval();
                }
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('[sanitizer] Ошибка итерации: %s', $e->getMessage()));
                $this->sleepInterval();
            }
        }

        $this->logger->info('[sanitizer] Остановлен');
    }

    /**
     * Обрабатывает одну identity из карантина.
     *
     * @return bool true если identity была обработана, false если очередь пуста
     */
    public function processOnce(): bool
    {
        $queueLen = $this->pool->getTaskQueueLength();
        if ($queueLen > 0) {
            $this->logger->debug(sprintf('[sanitizer] Пропуск: %d задач в очереди', $queueLen));
            return false;
        }
        $identity = $this->pool->popQuarantine();
        if ($identity === null) {
            return false;
        }

        $this->logger->info(sprintf(
            '[sanitizer] Обработка identity %s (прокси: %s)',
            substr($identity->id, 0, 8),
            $identity->maskedProxy(),
        ));

        // Для ротационных прокси с rotation_url — переключаем IP перед прогревом
        if ($identity->proxyType === 'rotating' && $identity->proxyAddress !== null) {
            $this->tryRotateProxy($identity->proxyAddress);
        }
        // Сброс cookies (могут быть «токсичными» — заблокированными Ozon)
        // и попытка получить свежие через solver
        $newIdentity = $this->pool->warmIdentity(
            $identity->proxyAddress,
            $identity->proxyType,
        );

        if ($newIdentity !== null) {
            // Прокси жив, новые cookies получены — warmIdentity уже добавила в ready
            // Старую identity удаляем
            $this->pool->deleteIdentity($identity);
            $this->logger->info(sprintf(
                '[sanitizer] Identity %s заменена на %s (прокси жив)',
                substr($identity->id, 0, 8),
                substr($newIdentity->id, 0, 8),
            ));
            return true;
        }

        // Прокси не ответил — записываем failure для static прокси
        if ($identity->proxyType === 'static' && $identity->proxyAddress !== null) {
            $proxyId = md5($identity->proxyAddress);
            $this->healthTracker->recordFailure($proxyId);
            $this->logger->warning(sprintf(
                '[sanitizer] Прокси %s не ответил — записан failure (health)',
                $identity->maskedProxy(),
            ));
        }

        // Удаляем identity — прокси неработоспособен
        $this->pool->deleteIdentity($identity);
        $this->logger->warning(sprintf(
            '[sanitizer] Identity %s удалена — прокси %s не восстановлен',
            substr($identity->id, 0, 8),
            $identity->maskedProxy(),
        ));

        return true;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function sleepInterval(): void
    {
        if (!$this->running) {
            return;
        }

        if (class_exists(\Swoole\Coroutine::class)) {
            \Swoole\Coroutine::sleep($this->config->sanitizerIntervalSeconds);
        } else {
            sleep($this->config->sanitizerIntervalSeconds);
        }
    }

    /**
     * Вызывает rotation_url для смены IP ротационного прокси.
     *
     * HTTP GET на URL провайдера → провайдер переключает IP на следующий.
     * После смены ждём 3с чтобы новый IP успел примениться.
     */
    private function tryRotateProxy(string $proxyAddress): void
    {
        $rotationUrl = $this->proxyProvider->getRotationUrlByAddress($proxyAddress);
        if ($rotationUrl === null) {
            return;
        }

        $masked = $this->maskProxy($proxyAddress);

        try {
            $this->logger->info(sprintf(
                '[sanitizer] Ротация IP для %s...',
                $masked,
            ));

            $ch = curl_init($rotationUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                // Обходим env http_proxy — ротация идёт напрямую к провайдеру
                CURLOPT_PROXY => '',
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error !== '') {
                $this->logger->warning(sprintf(
                    '[sanitizer] Ошибка ротации %s: %s',
                    $masked,
                    $error,
                ));
                return;
            }

            $this->logger->info(sprintf(
                '[sanitizer] Ротация %s: HTTP %d, ответ: %s',
                $masked,
                $httpCode,
                is_string($response) ? substr($response, 0, 200) : '(empty)',
            ));

            // Ждём применения нового IP
            if (class_exists(\Swoole\Coroutine::class)) {
                \Swoole\Coroutine::sleep(3.0);
            } else {
                sleep(3);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                '[sanitizer] Exception при ротации %s: %s',
                $masked,
                $e->getMessage(),
            ));
        }
    }

    private function maskProxy(string $proxy): string
    {
        $parts = explode('@', $proxy);
        return count($parts) > 1 ? '***@' . end($parts) : $proxy;
    }
}
