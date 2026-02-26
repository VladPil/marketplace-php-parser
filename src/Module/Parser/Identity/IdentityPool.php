<?php

declare(strict_types=1);

namespace App\Module\Parser\Identity;

use App\Module\Parser\Proxy\ProxyProvider;
use App\Module\Parser\Queue\RedisConnectionPool;
use App\Shared\Contract\SolverClientInterface;
use App\Shared\DTO\SessionData;
use App\Shared\Infrastructure\WithRedisConnectionTrait;
use App\Shared\Logging\ParseLogger;

/**
 * Redis-backed пул identity с атомарными операциями claim/release/quarantine.
 *
 * Redis-ключи:
 *   mp:identity:ready      (LIST)   — очередь готовых identity (FIFO)
 *   mp:identity:active     (SET)    — захваченные воркерами
 *   mp:identity:quarantine (LIST)   — очередь на проверку sanitizer
 *   mp:identity:data:{id}  (STRING) — JSON-данные identity
 */
final class IdentityPool
{
    use WithRedisConnectionTrait;

    private const KEY_READY = 'mp:identity:ready';
    private const KEY_ACTIVE = 'mp:identity:active';
    private const KEY_QUARANTINE = 'mp:identity:quarantine';
    private const KEY_DATA_PREFIX = 'mp:identity:data:';

    public function __construct(
        private readonly RedisConnectionPool $pool,
        private readonly IdentityPoolConfig $config,
        private readonly SolverClientInterface $solverClient,
        private readonly ProxyProvider $proxyProvider,
        private readonly ParseLogger $logger,
    ) {}

    /**
     * Атомарно захватывает готовую identity из пула.
     *
     * LPOP из ready → проверка TTL → SADD в active → return.
     * При отсутствии готовых identity возвращает null —
     * вызывающий код создаёт identity inline через warmIdentity().
     */
    public function claim(string $taskId): ?Identity
    {
        // Попытка найти валидную identity (с проверкой TTL и наличия прокси)
        for ($i = 0; $i < 10; $i++) {
            $identityId = $this->withRedis(static function (\Redis $redis): ?string {
                $id = $redis->lPop(self::KEY_READY);
                return $id === false ? null : (string) $id;
            });

            if ($identityId === null) {
                return null;
            }

            $identity = $this->loadIdentity($identityId);
            if ($identity === null) {
                $this->deleteIdentityData($identityId);
                continue;
            }

            if ($identity->isExpired($this->config->identityTtlSeconds)) {
                $this->deleteIdentityData($identityId);
                $this->logger->debug(sprintf(
                    '[identity] %s истекла (TTL %dс), удалена',
                    substr($identityId, 0, 8),
                    $this->config->identityTtlSeconds,
                ));
                continue;
            }

            // Проверяем что прокси всё ещё доступен в конфигурации
            if ($identity->proxyAddress !== null && !$this->isProxyStillAvailable($identity->proxyAddress)) {
                $this->deleteIdentityData($identityId);
                $this->logger->info(sprintf(
                    '[identity] %s удалена — прокси %s больше не в конфигурации',
                    substr($identityId, 0, 8),
                    $identity->maskedProxy(),
                ));
                continue;
            }

            // Захватываем identity
            $identity->markActive($taskId);
            $this->saveIdentity($identity);

            $this->withRedis(static function (\Redis $redis) use ($identityId): void {
                $redis->sAdd(self::KEY_ACTIVE, $identityId);
            });

            $this->logger->info(sprintf(
                '[identity] Захвачена %s (прокси: %s, cookies: %d)',
                substr($identityId, 0, 8),
                $identity->maskedProxy(),
                $identity->getSession() !== null ? count($identity->getSession()->cookies) : 0,
            ));

            return $identity;
        }

        return null;
    }

    /**
     * Возвращает identity в пул ready (с обновлённой сессией если есть).
     */
    public function release(Identity $identity, ?SessionData $updatedSession = null): void
    {
        if ($updatedSession !== null) {
            $identity->updateSession($updatedSession);
        }

        $identity->markReady();
        $this->saveIdentity($identity);

        $this->withRedis(static function (\Redis $redis) use ($identity): void {
            $redis->sRem(self::KEY_ACTIVE, $identity->id);
            $redis->rPush(self::KEY_READY, $identity->id);
        });

        $this->logger->info(sprintf(
            '[identity] Возвращена %s в пул ready',
            substr($identity->id, 0, 8),
        ));
    }

    /**
     * Перемещает identity в карантин (подозрение на блокировку 403).
     */
    public function quarantine(Identity $identity): void
    {
        $identity->markQuarantine();
        $this->saveIdentity($identity);

        $this->withRedis(static function (\Redis $redis) use ($identity): void {
            $redis->sRem(self::KEY_ACTIVE, $identity->id);
            $redis->rPush(self::KEY_QUARANTINE, $identity->id);
        });

        $this->logger->warning(sprintf(
            '[identity] %s перемещена в карантин (прокси: %s)',
            substr($identity->id, 0, 8),
            $identity->maskedProxy(),
        ));
    }

    /**
     * Создаёт новую identity: вызывает solver /solve для получения cookies.
     *
     * @return Identity|null null если solver не смог получить cookies
     */
    public function warmIdentity(?string $proxyAddress, string $proxyType): ?Identity
    {
        $proxyLabel = $proxyAddress !== null ? $this->maskProxy($proxyAddress) : 'direct';

        $this->logger->info(sprintf(
            '[identity] Прогрев новой identity для прокси %s',
            $proxyLabel,
        ));

        $session = $this->solverClient->warmup($proxyAddress);

        if ($session === null) {
            $this->logger->warning(sprintf(
                '[identity] Solver не смог получить cookies для %s',
                $proxyLabel,
            ));
            return null;
        }

        // Минимум 7 cookies для полноценной работы с Ozon
        $cookieCount = count($session->cookies);
        if ($cookieCount < 7) {
            $this->logger->warning(sprintf(
                '[identity] Недостаточно cookies для %s: получено %d (минимум 7), identity отклонена',
                $proxyLabel,
                $cookieCount,
            ));
            return null;
        }

        $identity = Identity::create($proxyAddress, $proxyType, $session);
        $this->saveIdentity($identity);

        $this->withRedis(static function (\Redis $redis) use ($identity): void {
            $redis->rPush(self::KEY_READY, $identity->id);
        });

        $this->logger->info(sprintf(
            '[identity] Новая identity %s готова (прокси: %s, cookies: %d)',
            substr($identity->id, 0, 8),
            $proxyLabel,
            count($session->cookies),
        ));

        return $identity;
    }

    /**
     * Забирает identity из очереди карантина (для sanitizer).
     */
    public function popQuarantine(): ?Identity
    {
        $identityId = $this->withRedis(static function (\Redis $redis): ?string {
            $id = $redis->lPop(self::KEY_QUARANTINE);
            return $id === false ? null : (string) $id;
        });

        if ($identityId === null) {
            return null;
        }

        return $this->loadIdentity($identityId);
    }

    /**
     * Удаляет identity из всех структур Redis.
     */
    public function deleteIdentity(Identity $identity): void
    {
        $this->withRedis(static function (\Redis $redis) use ($identity): void {
            $redis->del(self::KEY_DATA_PREFIX . $identity->id);
            $redis->sRem(self::KEY_ACTIVE, $identity->id);
            $redis->lRem(self::KEY_READY, $identity->id, 0);
            $redis->lRem(self::KEY_QUARANTINE, $identity->id, 0);
        });
    }

    /**
     * Возвращает все ready identity (для warmer — подсчёт по прокси).
     *
     * @return Identity[]
     */
    public function getReadyIdentities(): array
    {
        $ids = $this->withRedis(static function (\Redis $redis): array {
            return $redis->lRange(self::KEY_READY, 0, -1) ?: [];
        });

        if (empty($ids)) {
            return [];
        }

        return $this->loadIdentities($ids);
    }


    /**
     * Возвращает ВСЕ identity из всех очередей (ready + active + quarantine).
     *
     * Используется warmer'ом для подсчёта identity по прокси —
     * чтобы не создавать дубликаты для прокси, у которых identity в active/quarantine.
     *
     * @return Identity[]
     */
    public function getAllIdentities(): array
    {
        $allIds = $this->withRedis(static function (\Redis $redis): array {
            return array_unique(array_merge(
                $redis->lRange(self::KEY_READY, 0, -1) ?: [],
                $redis->sMembers(self::KEY_ACTIVE) ?: [],
                $redis->lRange(self::KEY_QUARANTINE, 0, -1) ?: [],
            ));
        });

        if (empty($allIds)) {
            return [];
        }

        return $this->loadIdentities($allIds);
    }

    /**
     * Статистика пула для health-check и отладки.
     */
    public function getStats(): array
    {
        return $this->withRedis(static function (\Redis $redis): array {
            return [
                'ready' => (int) $redis->lLen(self::KEY_READY),
                'active' => (int) $redis->sCard(self::KEY_ACTIVE),
                'quarantine' => (int) $redis->lLen(self::KEY_QUARANTINE),
            ];
        });
    }


    /**
     * Длина очереди задач парсинга (mp:parse:tasks).
     *
     * Используется warmer'ом для пропуска прогрева, когда есть ожидающие задачи —
     * чтобы не занимать solver во время парсинга.
     */
    public function getTaskQueueLength(): int
    {
        return $this->withRedis(static function (\Redis $redis): int {
            return (int) $redis->lLen('mp:parse:tasks');
        });
    }

    /**
     * Полная очистка пула (для отладки / перезапуска).
     */
    public function flush(): void
    {
        $allIds = [];

        $this->withRedis(function (\Redis $redis) use (&$allIds): void {
            $allIds = array_merge(
                $redis->lRange(self::KEY_READY, 0, -1) ?: [],
                $redis->sMembers(self::KEY_ACTIVE) ?: [],
                $redis->lRange(self::KEY_QUARANTINE, 0, -1) ?: [],
            );

            $redis->del(self::KEY_READY, self::KEY_ACTIVE, self::KEY_QUARANTINE);
        });

        if (!empty($allIds)) {
            $keys = array_map(fn(string $id) => self::KEY_DATA_PREFIX . $id, array_unique($allIds));
            $this->withRedis(static function (\Redis $redis) use ($keys): void {
                $redis->del(...$keys);
            });
        }

        $this->logger->info(sprintf('[identity] Пул очищен (%d identity удалено)', count($allIds)));
    }

    private function loadIdentity(string $id): ?Identity
    {
        $json = $this->withRedis(static function (\Redis $redis) use ($id): string|false {
            return $redis->get(self::KEY_DATA_PREFIX . $id);
        });

        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        return is_array($data) ? Identity::fromArray($data) : null;
    }

    /**
     * Массовая загрузка identity через MGET.
     *
     * @param string[] $ids
     * @return Identity[]
     */
    private function loadIdentities(array $ids): array
    {
        $keys = array_map(fn(string $id) => self::KEY_DATA_PREFIX . $id, $ids);

        $values = $this->withRedis(static function (\Redis $redis) use ($keys): array {
            return $redis->mGet($keys) ?: [];
        });

        $identities = [];
        foreach ($values as $json) {
            if ($json === false || !is_string($json)) {
                continue;
            }
            $data = json_decode($json, true);
            if (is_array($data)) {
                $identities[] = Identity::fromArray($data);
            }
        }

        return $identities;
    }

    private function saveIdentity(Identity $identity): void
    {
        $json = json_encode($identity->toArray(), JSON_UNESCAPED_UNICODE);

        $this->withRedis(static function (\Redis $redis) use ($identity, $json): void {
            $redis->set(self::KEY_DATA_PREFIX . $identity->id, $json);
        });
    }

    private function deleteIdentityData(string $id): void
    {
        $this->withRedis(static function (\Redis $redis) use ($id): void {
            $redis->del(self::KEY_DATA_PREFIX . $id);
        });
    }

    private function isProxyStillAvailable(string $proxyAddress): bool
    {
        foreach ($this->proxyProvider->getAll() as $proxy) {
            if ($proxy['address'] === $proxyAddress) {
                return true;
            }
        }
        return false;
    }

    private function maskProxy(string $proxy): string
    {
        $parts = explode('@', $proxy);
        return count($parts) > 1 ? '***@' . end($parts) : $proxy;
    }
}
