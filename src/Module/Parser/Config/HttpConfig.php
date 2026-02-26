<?php

declare(strict_types=1);

namespace App\Module\Parser\Config;

/**
 * Конфигурация HTTP-клиента для парсера.
 *
 * Содержит настройки подключения к API маркетплейса,
 * включая поддержку прокси-серверов с авторизацией.
 */
final readonly class HttpConfig
{
    /** @var string[] Список прокси-серверов для ротации */
    public array $proxies;

    /**
     * @param string $apiHost Хост API маркетплейса
     * @param int $apiPort Порт API маркетплейса
     * @param bool $ssl Использовать HTTPS
     * @param int $connectionTimeoutSeconds Таймаут подключения (секунды)
     * @param int $requestTimeoutSeconds Таймаут запроса (секунды)
     * @param string $proxyList Список прокси через запятую (формат: protocol://user:pass@host:port)
     * @param bool $proxyEnabled Включить использование прокси
     */
    public function __construct(
        public string $apiHost,
        public int $apiPort,
        public bool $ssl,
        public int $connectionTimeoutSeconds,
        public int $requestTimeoutSeconds,
        ?string $proxyList,
        public bool $proxyEnabled,
    ) {
        $this->proxies = $this->parseProxyList($proxyList ?? '');
    }

    /**
     * Возвращает случайный прокси из списка для ротации.
     *
     * @return string|null URL прокси или null если прокси не настроены/отключены
     */
    public function getRandomProxy(): ?string
    {
        if (!$this->proxyEnabled || empty($this->proxies)) {
            return null;
        }

        return $this->proxies[array_rand($this->proxies)];
    }

    /**
     * Парсит строку с прокси-серверами (через запятую) в массив.
     *
     * @param string $proxyList Строка вида "http://user:pass@host:port,socks5://user:pass@host:port"
     * @return string[]
     */
    private function parseProxyList(string $proxyList): array
    {
        if ($proxyList === '') {
            return [];
        }

        $proxies = array_map('trim', explode(',', $proxyList));
        return array_filter($proxies, fn(string $proxy): bool => $proxy !== '');
    }
}
