<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

/**
 * Контракт пула соединений Redis.
 *
 * Абстрагирует конкретную реализацию пула для упрощения тестирования.
 * Продакшн-реализация: RedisConnectionPool (Swoole-based).
 * Тест-реализация: любой объект с get()/put().
 */
interface RedisPoolInterface
{
    /**
     * Получает соединение из пула.
     *
     * @return mixed Redis-соединение (или совместимый объект в тестах)
     */
    public function get(): mixed;

    /**
     * Возвращает соединение в пул.
     *
     * @param mixed $redis Соединение для возврата
     */
    public function put(mixed $redis): void;

    /**
     * Уменьшает счётчик размера пула без возврата соединения.
     *
     * Используется при дропе битых соединений.
     */
    public function drop(): void;
}
