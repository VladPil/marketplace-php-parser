<?php

declare(strict_types=1);

namespace App\Module\Parser\Proxy;

/**
 * DTO выбранного прокси для запроса.
 *
 * Содержит адрес прокси, его уникальный идентификатор и ключ для кеша сессий.
 */
final readonly class ProxySelection
{
    public function __construct(
        public ?string $address,
        public string $id,
        public string $sessionKey,
        /** Источник прокси: 'env', 'admin', 'direct' */
        public string $source = 'env',
        /** Тип прокси: 'static' или 'rotating' */
        public string $type = 'static',
    ) {}

    /**
     * Проверяет, является ли это прямым подключением (без прокси).
     */
    public function isDirect(): bool
    {
        return $this->address === null;
    }
}
