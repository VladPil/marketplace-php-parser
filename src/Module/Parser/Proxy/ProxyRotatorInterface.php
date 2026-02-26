<?php

declare(strict_types=1);

namespace App\Module\Parser\Proxy;

/**
 * Контракт для ротации прокси-серверов.
 *
 * Отвечает за выбор прокси для запроса, регистрацию успехов/ошибок
 * и управление health-score для circuit breaker.
 */
interface ProxyRotatorInterface
{
    /**
     * Выбирает прокси для запроса.
     *
     * @param string|null $stickyKey Ключ привязки (обычно taskId) для sticky-сессий
     * @return ProxySelection Выбранный прокси с метаданными
     */
    public function selectProxy(?string $stickyKey = null): ProxySelection;

    /**
     * Регистрирует успешный запрос через прокси.
     *
     * @param string $proxyId Уникальный идентификатор прокси
     */
    public function recordSuccess(string $proxyId): void;

    /**
     * Регистрирует неудачный запрос через прокси.
     *
     * @param string $proxyId Уникальный идентификатор прокси
     */
    public function recordFailure(string $proxyId): void;
}
