<?php

declare(strict_types=1);

namespace App\Module\Parser\Identity;

use App\Shared\DTO\SessionData;

/**
 * Атомарная единица пула — связка прокси + сессия (cookies, UA, Client Hints).
 *
 * Identity = {proxy + cookies + UA + clientHints + browserHeaders}.
 * Все запросы в рамках одной задачи используют одну identity,
 * что гарантирует консистентный fingerprint для Ozon.
 *
 * Жизненный цикл:
 *   ready → claim() → active → release() → ready
 *                              → quarantine() → [sanitizer] → ready | deleted
 */
final class Identity
{
    public const STATUS_READY = 'ready';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_QUARANTINE = 'quarantine';

    public function __construct(
        /** Уникальный идентификатор (UUID) */
        public readonly string $id,
        /** Адрес прокси (null = direct) */
        public readonly ?string $proxyAddress,
        /** Тип прокси: 'static' или 'rotating' */
        public readonly string $proxyType,
        /** Сессия браузера: cookies, UA, client hints */
        private ?SessionData $session,
        /** Текущий статус: ready / active / quarantine */
        private string $status,
        /** Время создания identity (unix timestamp) */
        public readonly float $createdAt,
        /** ID задачи, которая захватила identity */
        private ?string $claimedBy = null,
        /** Время захвата (unix timestamp) */
        private ?float $claimedAt = null,
    ) {}

    /**
     * Счётчик подряд идущих Guzzle 403 (runtime-only, НЕ сериализуется в Redis).
     * Используется для детекции rate limiting и ротации identity.
     */
    private int $guzzle403Count = 0;


    public function getSession(): ?SessionData
    {
        return $this->session;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getClaimedBy(): ?string
    {
        return $this->claimedBy;
    }

    public function getClaimedAt(): ?float
    {
        return $this->claimedAt;
    }



    /**
     * Обновляет сессию (новые cookies после browser fetch).
     */
    public function updateSession(SessionData $session): void
    {
        $this->session = $session;
    }

    /**
     * Переводит identity в состояние «активна» (захвачена задачей).
     */
    public function markActive(string $taskId): void
    {
        $this->status = self::STATUS_ACTIVE;
        $this->claimedBy = $taskId;
        $this->claimedAt = microtime(true);
    }

    /**
     * Переводит identity в состояние «готова» (возвращена в пул).
     */
    public function markReady(): void
    {
        $this->status = self::STATUS_READY;
        $this->claimedBy = null;
        $this->claimedAt = null;
    }

    /**
     * Переводит identity в карантин (подозрение на блокировку).
     */
    public function markQuarantine(): void
    {
        $this->status = self::STATUS_QUARANTINE;
        $this->claimedBy = null;
        $this->claimedAt = null;
    }

    /**
     * Инкрементирует счётчик подряд идущих Guzzle 403.
     */
    public function incrementGuzzle403(): void
    {
        $this->guzzle403Count++;
    }

    /**
     * Сбрасывает счётчик Guzzle 403 (успешный запрос прервал серию).
     */
    public function resetGuzzle403(): void
    {
        $this->guzzle403Count = 0;
    }

    /**
     * Проверяет, превышен ли порог последовательных 403 (identity «перегрета»).
     */
    public function isOverheated(int $threshold): bool
    {
        return $this->guzzle403Count >= $threshold;
    }

    /**
     * Текущее значение счётчика 403 (для логирования).
     */
    public function getGuzzle403Count(): int
    {
        return $this->guzzle403Count;
    }



    /**
     * Маскирует прокси для логирования.
     */
    public function maskedProxy(): string
    {
        if ($this->proxyAddress === null) {
            return 'direct';
        }

        $parts = explode('@', $this->proxyAddress);

        return count($parts) > 1 ? '***@' . end($parts) : $this->proxyAddress;
    }

    /**
     * Проверяет, истёк ли TTL identity.
     */
    public function isExpired(int $ttlSeconds): bool
    {
        return (microtime(true) - $this->createdAt) >= $ttlSeconds;
    }



    /**
     * Сериализует в массив для хранения в Redis.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'proxy_address' => $this->proxyAddress,
            'proxy_type' => $this->proxyType,
            'session' => $this->session?->toArray(),
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'claimed_by' => $this->claimedBy,
            'claimed_at' => $this->claimedAt,
        ];
    }

    /**
     * Восстанавливает из массива (десериализация из Redis).
     */
    public static function fromArray(array $data): self
    {
        $session = isset($data['session']) && is_array($data['session'])
            ? SessionData::fromArray($data['session'])
            : null;

        return new self(
            id: $data['id'],
            proxyAddress: $data['proxy_address'] ?? null,
            proxyType: $data['proxy_type'] ?? 'static',
            session: $session,
            status: $data['status'] ?? self::STATUS_READY,
            createdAt: (float) ($data['created_at'] ?? microtime(true)),
            claimedBy: $data['claimed_by'] ?? null,
            claimedAt: isset($data['claimed_at']) ? (float) $data['claimed_at'] : null,
        );
    }

    /**
     * Создаёт новую identity из данных solver-service.
     */
    public static function create(
        ?string $proxyAddress,
        string $proxyType,
        ?SessionData $session,
    ): self {
        return new self(
            id: self::generateId(),
            proxyAddress: $proxyAddress,
            proxyType: $proxyType,
            session: $session,
            status: self::STATUS_READY,
            createdAt: microtime(true),
        );
    }

    /**
     * Генерирует UUID для identity.
     */
    private static function generateId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );
    }
}
