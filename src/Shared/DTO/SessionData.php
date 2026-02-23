<?php

declare(strict_types=1);

namespace App\Shared\DTO;

/**
 * DTO сессии браузера, полученной от solver-service.
 *
 * Содержит cookies, User-Agent и прокси — всё необходимое для
 * выполнения HTTP-запросов с тем же fingerprint, что и у браузера.
 */
final readonly class SessionData
{
    /**
     * @param array<int, array{name: string, value: string, domain: string, path: string}> $cookies Cookies из браузера
     * @param string $userAgent User-Agent использованного браузера
     * @param string $proxy Идентификатор прокси (URL или 'direct')
     * @param float $createdAt Время создания сессии (unix timestamp)
     * @param array $clientHints Client Hints из браузера (sec-ch-ua, platform, mobile)
     * @param array<string, string> $browserHeaders Реальные HTTP-заголовки из запросов браузера для Guzzle
     */
    public function __construct(
        public array $cookies,
        public string $userAgent,
        public string $proxy,
        public float $createdAt,
        public array $clientHints = [],
        public array $browserHeaders = [],
    ) {}

    /**
     * Создаёт SessionData из ответа solver-service.
     *
     * @param array $response Декодированный JSON-ответ от solver
     * @param string $proxy Прокси, использованный для запроса
     */
    public static function fromSolverResponse(array $response, string $proxy): self
    {
        $cookies = array_map(
            static fn(array $cookie): array => [
                'name' => $cookie['name'],
                'value' => $cookie['value'],
                'domain' => $cookie['domain'],
                'path' => $cookie['path'] ?? '/',
            ],
            $response['cookies'] ?? [],
        );

        return new self(
            cookies: $cookies,
            userAgent: $response['user_agent'] ?? '',
            proxy: $proxy,
            createdAt: microtime(true),
            clientHints: $response['client_hints'] ?? [],
            browserHeaders: $response['browser_headers'] ?? [],
        );
    }

    /**
     * Сериализует в массив для хранения в Redis.
     */
    public function toArray(): array
    {
        return [
            'cookies' => $this->cookies,
            'user_agent' => $this->userAgent,
            'proxy' => $this->proxy,
            'created_at' => $this->createdAt,
            'client_hints' => $this->clientHints,
            'browser_headers' => $this->browserHeaders,
        ];
    }

    /**
     * Восстанавливает из массива (десериализация из Redis).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            cookies: $data['cookies'] ?? [],
            userAgent: $data['user_agent'] ?? '',
            proxy: $data['proxy'] ?? 'direct',
            createdAt: $data['created_at'] ?? microtime(true),
            clientHints: $data['client_hints'] ?? [],
            browserHeaders: $data['browser_headers'] ?? [],
        );
    }
}
