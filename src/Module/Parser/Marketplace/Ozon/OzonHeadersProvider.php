<?php

declare(strict_types=1);

namespace App\Module\Parser\Marketplace\Ozon;

use App\Shared\Contract\HeadersProviderInterface;
use App\Shared\DTO\SessionData;

final class OzonHeadersProvider implements HeadersProviderInterface
{
    private const USER_AGENTS = [
        'ozonapp_android/17.35.1+2508 (SM-G998B; Android 14; ru_RU)',
        'ozonapp_android/17.34.0+2495 (Pixel 7; Android 14; ru_RU)',
        'ozonapp_android/17.33.2+2487 (SM-A546B; Android 13; ru_RU)',
        'ozonapp_android/17.32.1+2470 (Redmi Note 12; Android 13; ru_RU)',
        'ozonapp_android/17.31.0+2456 (Pixel 6a; Android 14; ru_RU)',
    ];

    private const APP_VERSIONS = [
        '17.35.1',
        '17.34.0',
        '17.33.2',
        '17.32.1',
        '17.31.0',
    ];

    /** @var SessionData|null Данные сессии solver (UA + Client Hints) */
    private ?SessionData $sessionData = null;

    /**
     * Устанавливает данные сессии от solver.
     *
     * Когда установлены — getHeaders() возвращает браузерные заголовки
     * с правильными Sec-Fetch-*, Client Hints и Referer для API-запросов.
     */
    public function setSessionData(?SessionData $session): void
    {
        $this->sessionData = $session;
    }

    public function getHeaders(): array
    {
        // Браузерная сессия от solver — используем реальные заголовки браузера
        if ($this->sessionData !== null) {
            // Если solver вернул полный набор заголовков — используем их напрямую
            if (!empty($this->sessionData->browserHeaders)) {
                $headers = $this->sessionData->browserHeaders;

                // Убираем заголовки, которые Guzzle сам управляет
                unset($headers['Host'], $headers['host']);
                unset($headers['Cookie'], $headers['cookie']);
                unset($headers['Content-Length'], $headers['content-length']);

                return $headers;
            }

            // Fallback: собираем заголовки вручную из clientHints
            $headers = [
                'User-Agent' => $this->sessionData->userAgent,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept-Encoding' => 'gzip, deflate, br, zstd',
                'Connection' => 'keep-alive',
                'Referer' => 'https://www.ozon.ru/',
                'Origin' => 'https://www.ozon.ru',
                'Sec-Fetch-Dest' => 'empty',
                'Sec-Fetch-Mode' => 'cors',
                'Sec-Fetch-Site' => 'same-origin',
                // Обязательные кастомные заголовки Ozon для API-запросов
                'x-o3-app-name' => 'dweb_client',
                'x-o3-app-version' => $this->sessionData->clientHints['appVersion'] ?? '',
                'x-o3-manifest-version' => $this->sessionData->clientHints['manifestVersion'] ?? '',
            ];

            // Убираем пустые значения заголовков
            $headers = array_filter($headers, static fn (string $v): bool => $v !== '');

            // Client Hints из браузера или генерация из User-Agent
            $hints = $this->buildClientHints();
            if ($hints !== []) {
                $headers = array_merge($headers, $hints);
            }

            return $headers;
        }

        $index = array_rand(self::USER_AGENTS);

        return [
            'User-Agent' => self::USER_AGENTS[$index],
            'x-o3-app-name' => 'ozonapp_android',
            'x-o3-device-type' => 'mobile',
            'x-o3-app-version' => self::APP_VERSIONS[$index],
            'Accept' => 'application/json',
            'Accept-Language' => 'ru-RU,ru;q=0.9',
            'Accept-Encoding' => 'gzip, deflate',
            'Connection' => 'keep-alive',
        ];
    }

    /**
     * Формирует заголовки Client Hints из данных сессии или User-Agent.
     *
     * @return array<string, string>
     */
    private function buildClientHints(): array
    {
        $hints = $this->sessionData->clientHints ?? [];

        // Есть реальные Client Hints от браузера
        if (!empty($hints['brands'])) {
            $brandParts = [];
            foreach ($hints['brands'] as $brand) {
                $brandParts[] = sprintf('"%s";v="%s"', $brand['brand'] ?? '', $brand['version'] ?? '');
            }

            return [
                'sec-ch-ua' => implode(', ', $brandParts),
                'sec-ch-ua-platform' => sprintf('"%s"', $hints['platform'] ?? 'Linux'),
                'sec-ch-ua-mobile' => ($hints['mobile'] ?? false) ? '?1' : '?0',
            ];
        }

        // Генерация из User-Agent (fallback)
        $ua = $this->sessionData->userAgent ?? '';
        if (preg_match('/Chrome\/(\d+)/', $ua, $m)) {
            $version = $m[1];

            return [
                'sec-ch-ua' => sprintf('"Chromium";v="%s", "Google Chrome";v="%s", "Not-A.Brand";v="99"', $version, $version),
                'sec-ch-ua-platform' => '"Linux"',
                'sec-ch-ua-mobile' => '?0',
            ];
        }

        return [];
    }
}
