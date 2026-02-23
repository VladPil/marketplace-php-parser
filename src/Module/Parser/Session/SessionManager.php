<?php

declare(strict_types=1);

namespace App\Module\Parser\Session;

use App\Module\Parser\Config\RedisConfig;
use App\Module\Parser\Config\SolverConfig;
use App\Module\Parser\Queue\RedisConnectionPool;
use App\Shared\Contract\SessionManagerInterface;
use App\Shared\Contract\SolverClientInterface;
use App\Shared\DTO\SessionData;
use App\Shared\Entity\SolverSession;
use App\Shared\Infrastructure\WithRedisConnectionTrait;
use App\Shared\Logging\ParseLogger;
use App\Shared\Repository\SolverSessionRepository;
use App\Shared\Tracing\TraceContext;

/**
 * Менеджер сессий с кешированием в Redis.
 *
 * Реализует ленивую загрузку сессии: при первом запросе обращается к solver-service,
 * кеширует результат в Redis с TTL. Использует Redis-lock для предотвращения
 * thundering herd — когда несколько воркеров одновременно запрашивают сессию.
 */
final class SessionManager implements SessionManagerInterface
{
    use WithRedisConnectionTrait;

    /** @var string Префикс ключей Redis для сессий */
    private readonly string $keyPrefix;

    /** @var int Таймаут ожидания lock (секунды) */
    private const LOCK_WAIT_TIMEOUT = 30;

    /** @var int TTL lock на запрос к solver (секунды) */
    private const LOCK_TTL = 60;

    public function __construct(
        private readonly RedisConnectionPool $pool,
        private readonly SolverClientInterface $solverClient,
        private readonly SolverConfig $solverConfig,
        private readonly RedisConfig $redisConfig,
        private readonly ParseLogger $logger,
        private readonly SolverSessionRepository $sessionRepository,
    ) {
        $this->keyPrefix = $this->redisConfig->prefix . 'session:';
    }

    public function getSession(string $proxy): ?SessionData
    {
        // Захватываем taskId сразу — в Swoole static-контекст может измениться при переключении coroutine
        $taskId = TraceContext::getTaskId();

        // Проверяем кеш
        $cached = $this->getFromCache($proxy);
        if ($cached !== null) {
            return $cached;
        }

        // Пытаемся взять lock на запрос к solver (предотвращение thundering herd)
        $lockKey = $this->keyPrefix . 'lock:' . md5($proxy);
        $acquired = $this->acquireLock($lockKey);

        if (!$acquired) {
            // Другой воркер уже запрашивает сессию — ждём появления в кеше
            $this->logger->debug(
                'Ожидание сессии от другого воркера',
                ['proxy' => $this->maskProxy($proxy), 'channel' => 'solver'],
            );
            return $this->waitForCache($proxy, $taskId);
        }

        try {
            // Повторная проверка кеша (мог появиться пока ждали lock)
            $cached = $this->getFromCache($proxy);
            if ($cached !== null) {
                return $cached;
            }

            // Запрашиваем у solver
            return $this->solveWithRetry($proxy, $taskId);
        } finally {
            $this->releaseLock($lockKey);
        }
    }

    public function invalidateSession(string $proxy): void
    {
        $key = $this->buildKey($proxy);

        $this->withRedis(static function (\Redis $redis) use ($key): void {
            $redis->del($key);
        });

        $this->logger->info(
            'Сессия удалена из кеша, следующий запрос получит свежую',
            ['proxy' => $this->maskProxy($proxy), 'channel' => 'solver'],
        );
    }

    /**
     * Запрашивает сессию у solver с повторными попытками и прогрессивной задержкой.
     */
    private function solveWithRetry(string $proxy, ?string $taskId = null): ?SessionData
    {
        // Навигируем solver на реальную страницу товара — там JS ставит все cookies
        // (abt_data, xcid, rfuid, __Secure-ext_xcid и др.), которых нет на главной
        $url = 'https://www.ozon.ru/product/balaklava-turn-off-on-new-965672325/';

        for ($attempt = 1; $attempt <= $this->solverConfig->maxSolveRetries; $attempt++) {
            $proxyValue = $proxy === 'direct' ? null : $proxy;
            $session = $this->solverClient->solve($url, $proxyValue);

            if ($session !== null) {
                $this->saveToCache($proxy, $session);
                $this->persistSession($session, 'success', taskId: $taskId);
                $this->logger->info(
                    sprintf(
                        'Сессия получена от solver и закеширована (%d cookies, TTL %dс)',
                        count($session->cookies),
                        $this->solverConfig->sessionTtlSeconds,
                    ),
                    ['proxy' => $this->maskProxy($proxy), 'attempt' => $attempt, 'channel' => 'solver'],
                );
                return $session;
            }

            if ($attempt < $this->solverConfig->maxSolveRetries) {
                $delay = $attempt;
                $this->logger->warning(
                    sprintf('Solver не вернул сессию (попытка %d/%d), повтор через %dс', $attempt, $this->solverConfig->maxSolveRetries, $delay),
                    ['proxy' => $this->maskProxy($proxy), 'channel' => 'solver'],
                );
                usleep($delay * 1_000_000);
            }
        }

        // Сохраняем запись об ошибке
        $this->persistSession(
            null,
            'error',
            $proxy,
            sprintf('Solver недоступен после %d попыток', $this->solverConfig->maxSolveRetries),
            $taskId,
        );

        $this->logger->error(
            sprintf('Solver недоступен после %d попыток — запросы пойдут без cookies', $this->solverConfig->maxSolveRetries),
            ['proxy' => $this->maskProxy($proxy), 'channel' => 'solver'],
        );

        return null;
    }

    /**
     * Сохраняет запись о сессии solver в БД для аудита и отладки.
     */
    private function persistSession(
        ?SessionData $session,
        string $status,
        ?string $proxyOverride = null,
        ?string $errorMessage = null,
        ?string $taskId = null,
    ): void {
        try {
            $taskId ??= TraceContext::getTaskId();
            if ($taskId === null) {
                $this->logger->warning(
                    'Сессия сохраняется без привязки к задаче (TraceContext::getTaskId() = null)',
                    ['channel' => 'solver'],
                );
            }

            $entity = new SolverSession();
            $entity->setParseTaskId($taskId);
            $entity->setStatus($status);

            if ($session !== null) {
                $entity->setCookies($session->cookies);
                $entity->setUserAgent($session->userAgent);
                $entity->setClientHints($session->clientHints);
                $entity->setProxy($session->proxy);
            } else {
                $entity->setProxy($proxyOverride ?? 'direct');
                $entity->setErrorMessage($errorMessage);
            }

            $this->sessionRepository->save($entity);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Не удалось сохранить сессию в БД: %s', $e->getMessage()),
                ['exception' => $e::class, 'channel' => 'solver'],
            );
        }
    }

    /**
     * Ожидает появления сессии в кеше (другой воркер её запрашивает).
     */
    private function waitForCache(string $proxy, ?string $taskId = null): ?SessionData
    {
        $deadline = time() + self::LOCK_WAIT_TIMEOUT;

        while (time() < $deadline) {
            usleep(500_000); // 0.5с между проверками

            $cached = $this->getFromCache($proxy);
            if ($cached !== null) {
                $this->logger->debug(
                    'Сессия появилась в кеше (от другого воркера)',
                    ['proxy' => $this->maskProxy($proxy), 'channel' => 'solver'],
                );
                return $cached;
            }
        }

        // Таймаут ожидания — пробуем сами
        $this->logger->warning(
            'Таймаут ожидания сессии от другого воркера, запрашиваю сам',
            ['proxy' => $this->maskProxy($proxy), 'channel' => 'solver'],
        );
        return $this->solveWithRetry($proxy, $taskId);
    }

    /**
     * Пытается взять distributed lock в Redis.
     */
    private function acquireLock(string $lockKey): bool
    {
        return $this->withRedis(static function (\Redis $redis) use ($lockKey): bool {
            return (bool) $redis->set($lockKey, '1', ['NX', 'EX' => self::LOCK_TTL]);
        });
    }

    /**
     * Освобождает distributed lock.
     */
    private function releaseLock(string $lockKey): void
    {
        $this->withRedis(static function (\Redis $redis) use ($lockKey): void {
            $redis->del($lockKey);
        });
    }

    /**
     * Получает сессию из Redis-кеша.
     */
    private function getFromCache(string $proxy): ?SessionData
    {
        $key = $this->buildKey($proxy);

        $data = $this->withRedis(static function (\Redis $redis) use ($key): string|false {
            return $redis->get($key);
        });

        if ($data === false) {
            return null;
        }

        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return null;
        }

        return SessionData::fromArray($decoded);
    }

    /**
     * Сохраняет сессию в Redis с TTL.
     */
    private function saveToCache(string $proxy, SessionData $session): void
    {
        $key = $this->buildKey($proxy);
        $data = json_encode($session->toArray(), JSON_UNESCAPED_UNICODE);

        $this->withRedis(function (\Redis $redis) use ($key, $data): void {
            $redis->setex($key, $this->solverConfig->sessionTtlSeconds, $data);
        });
    }

    /**
     * Формирует ключ Redis для кеша сессии.
     */
    private function buildKey(string $proxy): string
    {
        return $this->keyPrefix . md5($proxy);
    }

    public function cacheSession(string $proxy, SessionData $session): void
    {
        $this->saveToCache($proxy, $session);
        $this->persistSession($session, 'success', taskId: TraceContext::getTaskId());
    }

    /**
     * Маскирует прокси для логирования.
     */
    private function maskProxy(string $proxy): string
    {
        if ($proxy === 'direct') {
            return 'direct';
        }

        $parts = explode('@', $proxy);
        return count($parts) > 1 ? '***@' . end($parts) : $proxy;
    }
}
