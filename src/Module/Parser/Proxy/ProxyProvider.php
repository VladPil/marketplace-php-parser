<?php

declare(strict_types=1);

namespace App\Module\Parser\Proxy;

use App\Module\Parser\Config\HttpConfig;
use App\Shared\Repository\ProxyRepository;

/**
 * Объединяет прокси из ENV (HttpConfig) и из БД (админка).
 * Каждый прокси сопровождается меткой источника (source).
 *
 * Кеш обновляется автоматически по TTL, чтобы долгоживущий
 * Swoole-процесс подхватывал прокси, добавленные через админку.
 */
final class ProxyProvider
{
    /** TTL кеша в секундах — как часто перечитывать БД */
    private const CACHE_TTL_SECONDS = 60;

    /** @var array{address: string, source: string, type: string, rotation_url: string|null}[]|null */
    private ?array $cached = null;

    /** Время последнего обновления кеша (unix timestamp) */
    private float $cachedAt = 0.0;

    public function __construct(
        private readonly HttpConfig $httpConfig,
        private readonly ProxyRepository $proxyRepository,
    ) {}

    /**
     * @return array{address: string, source: string, type: string, rotation_url: string|null}[]
     */
    public function getAll(): array
    {
        if ($this->cached !== null && !$this->isCacheExpired()) {
            return $this->cached;
        }

        $result = [];

        // ENV-прокси
        foreach ($this->httpConfig->proxies as $address) {
            $result[] = ['address' => $address, 'source' => 'env', 'type' => 'static', 'rotation_url' => null];
        }

        // DB-прокси (только включённые, без дублей с ENV)
        try {
            $envAddresses = array_flip($this->httpConfig->proxies);
            foreach ($this->proxyRepository->findAllEnabled() as $proxy) {
                if (!isset($envAddresses[$proxy->getAddress()])) {
                    $result[] = ['address' => $proxy->getAddress(), 'source' => 'admin', 'type' => $proxy->getType(), 'rotation_url' => $proxy->getRotationUrl()];
                }
            }
        } catch (\Throwable) {
            // БД недоступна — работаем только с ENV
        }

        $this->cached = $result;
        $this->cachedAt = microtime(true);
        return $result;
    }

    /** @return string[] */
    public function getAddresses(): array
    {
        return array_column($this->getAll(), 'address');
    }

    public function getSourceByAddress(string $address): string
    {
        foreach ($this->getAll() as $item) {
            if ($item['address'] === $address) {
                return $item['source'];
            }
        }
        return 'env';
    }

    public function getTypeByAddress(string $address): string
    {
        foreach ($this->getAll() as $item) {
            if ($item['address'] === $address) {
                return $item['type'];
            }
        }
        return 'static';
    }


    /**
     * Возвращает URL для ротации IP (HTTP GET) для данного прокси.
     *
     * Если прокси не имеет rotation_url — возвращает null.
     */
    public function getRotationUrlByAddress(string $address): ?string
    {
        foreach ($this->getAll() as $item) {
            if ($item['address'] === $address) {
                return $item['rotation_url'];
            }
        }
        return null;
    }

    /**
     * Проверяет, является ли прокси ротационным по его ID (md5 от адреса).
     */
    public function isRotatingById(string $proxyId): bool
    {
        foreach ($this->getAll() as $item) {
            if (md5($item['address']) === $proxyId) {
                return $item['type'] === 'rotating';
            }
        }
        return false;
    }

    public function hasProxies(): bool
    {
        return !empty($this->getAll());
    }

    public function invalidateCache(): void
    {
        $this->cached = null;
        $this->cachedAt = 0.0;
    }

    private function isCacheExpired(): bool
    {
        return (microtime(true) - $this->cachedAt) >= self::CACHE_TTL_SECONDS;
    }
}
