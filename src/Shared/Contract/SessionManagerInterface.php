<?php

declare(strict_types=1);

namespace App\Shared\Contract;

use App\Shared\DTO\SessionData;

/**
 * Контракт менеджера сессий для кеширования cookies от solver-service.
 */
interface SessionManagerInterface
{
    /**
     * Получает сессию для указанного прокси (из кеша или от solver).
     *
     * @param string $proxy Идентификатор прокси (URL или 'direct')
     * @return SessionData|null Данные сессии или null если недоступно
     */
    public function getSession(string $proxy): ?SessionData;

    /**
     * Инвалидирует закешированную сессию (например, при HTTP 403).
     *
     * @param string $proxy Идентификатор прокси
     */
    public function invalidateSession(string $proxy): void;

    /**
     * Кеширует готовую сессию (полученную от browser fetch).
     *
     * @param string $proxy Идентификатор прокси
     * @param SessionData $session Данные сессии для кеширования
     */
    public function cacheSession(string $proxy, SessionData $session): void;
}
