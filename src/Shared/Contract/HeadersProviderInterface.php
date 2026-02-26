<?php

declare(strict_types=1);

namespace App\Shared\Contract;

/**
 * Контракт для предоставления HTTP-заголовков.
 *
 * Реализации формируют набор заголовков, необходимых
 * для выполнения запросов к внешним сервисам (например, маркетплейсу).
 */
interface HeadersProviderInterface
{
    /**
     * Возвращает массив HTTP-заголовков для запроса.
     *
     * @return array Ассоциативный массив заголовков (имя => значение)
     */
    public function getHeaders(): array;
}
