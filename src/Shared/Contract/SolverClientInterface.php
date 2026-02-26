<?php

declare(strict_types=1);

namespace App\Shared\Contract;

use App\Shared\DTO\SessionData;

/**
 * Контракт HTTP-клиента для взаимодействия с solver-service.
 */
interface SolverClientInterface
{
    /**
     * Запрашивает обход анти-бот защиты для указанного URL.
     *
     * @param string $url URL страницы для обхода защиты
     * @param string|null $proxy URL прокси-сервера (для совпадения IP fingerprint)
     * @return SessionData|null Данные сессии или null при ошибке
     */
    public function solve(string $url, ?string $proxy = null): ?SessionData;


    /**
     * Прогрев identity: мульти-навигация (главная → товар) для накопления cookies.
     *
     * @param string|null $proxy URL прокси-сервера
     * @return SessionData|null Данные сессии с 7-10 cookies или null
     */
    public function warmup(?string $proxy = null): ?SessionData;
    /**
     * Выполняет fetch-запрос через браузер solver-service.
     *
     * Запрос проходит через Chrome fetch() с правильным TLS fingerprint
     * и cookies, обходя блокировки по JA3/JA4.
     *
     * @param string $url Полный URL для запроса
     * @param string|null $proxy Прокси-сервер
     * @param SessionData|null $session Данные сессии identity (cookies для инжекции в браузер)
     * @return array|null Декодированный JSON-ответ или null при ошибке
     */
    public function fetch(string $url, ?string $proxy = null, ?SessionData $session = null): ?array;

    /**
     * Выполняет fetch-запрос через браузер solver-service и возвращает сырой HTML.
     *
     * В отличие от fetch(), не пытается декодировать тело как JSON —
     * возвращает строку HTML и данные сессии.
     *
     * @param string $url Полный URL для запроса
     * @param string|null $proxy Прокси-сервер
     * @param SessionData|null $session Данные сессии identity (cookies для инжекции в браузер)
     * @return array{html: string, session: SessionData|null}|null Сырой HTML + сессия или null при ошибке
     */
    public function fetchHtml(string $url, ?string $proxy = null, ?SessionData $session = null): ?array;
}
