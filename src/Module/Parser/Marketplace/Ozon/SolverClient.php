<?php

declare(strict_types=1);

namespace App\Module\Parser\Marketplace\Ozon;

use App\Module\Parser\Config\SolverConfig;
use App\Shared\Contract\SolverClientInterface;
use App\Shared\DTO\SessionData;
use App\Shared\Logging\ParseLogger;
use App\Shared\Tracing\TraceContext;
use GuzzleHttp\Client;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP-клиент для взаимодействия с solver-service.
 *
 * Отправляет запросы на обход анти-бот защиты и возвращает
 * данные сессии (cookies + User-Agent). При ошибке возвращает null,
 * не бросает исключения — парсер продолжает работу без cookies.
 */
final class SolverClient implements SolverClientInterface
{
    private Client $client;

    public function __construct(
        private readonly SolverConfig $config,
        private readonly ParseLogger $logger,
    ) {
        $this->client = new Client([
            'base_uri' => sprintf('http://%s:%d', $this->config->host, $this->config->port),
            'timeout' => $this->config->requestTimeoutSeconds,
            'connect_timeout' => $this->config->connectionTimeoutSeconds,
            'http_errors' => false,
        ]);
    }

    public function solve(string $url, ?string $proxy = null): ?SessionData
    {
        $traceId = TraceContext::getTraceId();
        $proxyLabel = $proxy !== null ? $this->maskProxy($proxy) : 'direct';

        $this->logger->info(
            sprintf('Запрос cookies у solver-service (%s → %s)', $proxyLabel, $url),
            ['url' => $url, 'proxy' => $proxyLabel, 'channel' => 'solver'],
        );

        try {
            $payload = ['url' => $url, 'trace_id' => $traceId];
            if ($proxy !== null) {
                $payload['proxy'] = $this->normalizeProxy($proxy);
            }

            $response = $this->client->post('/solve', [
                'json' => $payload,
                'headers' => [
                    'X-Trace-Id' => $traceId,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $status = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            if ($status !== Response::HTTP_OK || !($body['success'] ?? false)) {
                $error = $body['error'] ?? "HTTP {$status}";
                $this->logger->warning(
                    sprintf('Solver не смог получить cookies: %s', $error),
                    ['status' => $status, 'error' => $error, 'channel' => 'solver'],
                );
                return null;
            }

            $session = SessionData::fromSolverResponse($body, $proxy ?? 'direct');

            $this->logger->info(
                sprintf('Solver вернул %d cookies (UA: %s)', count($session->cookies), mb_substr($session->userAgent, 0, 60)),
                ['cookies_count' => count($session->cookies), 'channel' => 'solver'],
            );

            return $session;
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Solver-service недоступен: %s', $e->getMessage()),
                ['exception' => $e::class, 'channel' => 'solver'],
            );
            return null;
        }
    }


    public function warmup(?string $proxy = null): ?SessionData
    {
        $traceId = TraceContext::getTraceId();
        $proxyLabel = $proxy !== null ? $this->maskProxy($proxy) : 'direct';

        $this->logger->info(
            sprintf('Warmup identity через solver (%s)', $proxyLabel),
            ['proxy' => $proxyLabel, 'channel' => 'solver'],
        );

        try {
            $payload = ['url' => 'https://www.ozon.ru/', 'trace_id' => $traceId];
            if ($proxy !== null) {
                $payload['proxy'] = $this->normalizeProxy($proxy);
            }

            $response = $this->client->post('/warmup', [
                'json' => $payload,
                'timeout' => 120,
                'headers' => [
                    'X-Trace-Id' => $traceId,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $status = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            if ($status !== Response::HTTP_OK || !($body['success'] ?? false)) {
                $error = $body['error'] ?? "HTTP {$status}";
                $this->logger->warning(
                    sprintf('Warmup не получил cookies: %s', $error),
                    ['status' => $status, 'error' => $error, 'channel' => 'solver'],
                );
                return null;
            }

            $session = SessionData::fromSolverResponse($body, $proxy ?? 'direct');

            $this->logger->info(
                sprintf('Warmup вернул %d cookies (UA: %s)', count($session->cookies), mb_substr($session->userAgent, 0, 60)),
                ['cookies_count' => count($session->cookies), 'channel' => 'solver'],
            );

            return $session;
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Warmup solver-service недоступен: %s', $e->getMessage()),
                ['exception' => $e::class, 'channel' => 'solver'],
            );
            return null;
        }
    }

    public function fetch(string $url, ?string $proxy = null, ?SessionData $session = null): ?array
    {
        $traceId = TraceContext::getTraceId();

        $this->logger->info(
            sprintf('Browser fetch через solver: %s', $url),
            ['url' => $url, 'channel' => 'solver'],
        );

        try {
            $payload = ['url' => $url, 'trace_id' => $traceId];
            if ($proxy !== null) {
                $payload['proxy'] = $this->normalizeProxy($proxy);
            }
            if ($session !== null) {
                $payload['cookies'] = $session->cookies;
                if ($session->userAgent !== '') {
                    $payload['user_agent'] = $session->userAgent;
                }
            }

            // Debug-лог: payload перед отправкой в solver для сравнения с Guzzle-запросами
            $this->logger->debug(
                sprintf('[solver-debug] fetch payload: %s', $url),
                [
                    'proxy' => $proxy !== null ? $this->maskProxy($proxy) : 'direct',
                    'user_agent' => $session?->userAgent ?? 'not set',
                    'cookie_names' => $session !== null ? array_map(
                        static fn(array $c): string => $c['name'],
                        $session->cookies,
                    ) : [],
                    'cookie_count' => $session !== null ? count($session->cookies) : 0,
                    'has_browser_headers' => $session !== null && !empty($session->browserHeaders),
                    'channel' => 'solver',
                ],
            );
            $response = $this->client->post('/fetch', [
                'json' => $payload,
                'headers' => [
                    'X-Trace-Id' => $traceId,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $status = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            if ($status !== Response::HTTP_OK || !($body['success'] ?? false)) {
                $error = $body['error'] ?? "HTTP {$status}";
                $this->logger->warning(
                    sprintf('Browser fetch ошибка: %s (HTTP %d)', $error, $body['status'] ?? 0),
                    ['status' => $body['status'] ?? 0, 'error' => $error, 'channel' => 'solver'],
                );
                return null;
            }

            $responseBody = $body['body'] ?? null;
            if ($responseBody === null) {
                return null;
            }

            // Проверяем HTTP-статус целевой страницы (403 = блокировка)
            $targetStatus = (int) ($body['status'] ?? 0);
            if ($targetStatus === 403) {
                $this->logger->warning(
                    sprintf('Browser fetch: Ozon заблокировал запрос (HTTP %d)', $targetStatus),
                    ['url' => $url, 'status' => $targetStatus, 'channel' => 'solver'],
                );
                return null;
            }

            $decoded = json_decode($responseBody, true);
            if (!is_array($decoded)) {
                // HTML вместо JSON — вероятно страница блокировки
                $isBlocked = str_contains($responseBody, 'Доступ ограничен')
                    || str_contains($responseBody, 'access denied')
                    || str_contains($responseBody, 'blocked');
                $this->logger->warning(
                    sprintf(
                        'Browser fetch: ответ не является JSON%s',
                        $isBlocked ? ' (обнаружена страница блокировки Ozon)' : '',
                    ),
                    ['body_preview' => mb_substr($responseBody, 0, 300), 'is_blocked' => $isBlocked, 'channel' => 'solver'],
                );
                return null;
            }

            // Прикрепляем SessionData из cookies ответа для кеширования в парсере
            if (!empty($body['cookies'])) {
                $sessionData = SessionData::fromSolverResponse($body, $proxy ?? 'direct');
                $decoded['_session'] = $sessionData;

                $this->logger->info(
                    sprintf(
                        'Browser fetch успех: статус=%d, %d cookies (UA: %s)',
                        $body['status'] ?? 0,
                        count($sessionData->cookies),
                        mb_substr($sessionData->userAgent, 0, 50),
                    ),
                    ['channel' => 'solver'],
                );
            }

            return $decoded;
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Browser fetch исключение: %s', $e->getMessage()),
                ['exception' => $e::class, 'channel' => 'solver'],
            );
            return null;
        }
    }

    public function fetchHtml(string $url, ?string $proxy = null, ?SessionData $session = null): ?array
    {
        $traceId = TraceContext::getTraceId();

        $this->logger->info(
            sprintf('Browser fetch HTML через solver: %s', $url),
            ['url' => $url, 'channel' => 'solver'],
        );

        try {
            $payload = ['url' => $url, 'trace_id' => $traceId];
            if ($proxy !== null) {
                $payload['proxy'] = $this->normalizeProxy($proxy);
            }
            if ($session !== null) {
                $payload['cookies'] = $session->cookies;
                if ($session->userAgent !== '') {
                    $payload['user_agent'] = $session->userAgent;
                }
            }

            // Debug-лог: payload перед отправкой в solver для HTML-запроса
            $this->logger->debug(
                sprintf('[solver-debug] fetchHtml payload: %s', $url),
                [
                    'proxy' => $proxy !== null ? $this->maskProxy($proxy) : 'direct',
                    'user_agent' => $session?->userAgent ?? 'not set',
                    'cookie_names' => $session !== null ? array_map(
                        static fn(array $c): string => $c['name'],
                        $session->cookies,
                    ) : [],
                    'cookie_count' => $session !== null ? count($session->cookies) : 0,
                    'channel' => 'solver',
                ],
            );

            $response = $this->client->post('/fetch', [
                'json' => $payload,
                'headers' => [
                    'X-Trace-Id' => $traceId,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $status = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            if ($status !== Response::HTTP_OK || !($body['success'] ?? false)) {
                $error = $body['error'] ?? "HTTP {$status}";
                $this->logger->warning(
                    sprintf('Browser fetch HTML: solver ошибка: %s (HTTP %d)', $error, $body['status'] ?? 0),
                    ['status' => $body['status'] ?? 0, 'error' => $error, 'channel' => 'solver'],
                );
                return null;
            }

            // Проверяем HTTP-статус целевой страницы (не solver, а Ozon)
            $targetStatus = (int) ($body['status'] ?? 0);
            if ($targetStatus < Response::HTTP_OK || $targetStatus >= Response::HTTP_MULTIPLE_CHOICES) {
                $this->logger->warning(
                    sprintf('Browser fetch HTML: целевая страница вернула HTTP %d', $targetStatus),
                    ['url' => $url, 'status' => $targetStatus, 'channel' => 'solver'],
                );

                // Cookies от 403 бесполезны — не кешируем, сразу возвращаем null
                return null;
            }

            $responseBody = $body['body'] ?? null;
            if ($responseBody === null || $responseBody === '') {
                $this->logger->warning(
                    'Browser fetch HTML: пустое тело ответа',
                    ['url' => $url, 'channel' => 'solver'],
                );
                return null;
            }

            // Сессия + cookies для кеширования
            $sessionData = null;
            if (!empty($body['cookies'])) {
                $sessionData = SessionData::fromSolverResponse($body, $proxy ?? 'direct');
            }

            $this->logger->info(
                sprintf(
                    'Browser fetch HTML успех: статус=%d, размер=%d байт, %d cookies',
                    $targetStatus,
                    strlen($responseBody),
                    $sessionData !== null ? count($sessionData->cookies) : 0,
                ),
                ['channel' => 'solver'],
            );

            return [
                'html' => $responseBody,
                'session' => $sessionData,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Browser fetch HTML исключение: %s', $e->getMessage()),
                ['exception' => $e::class, 'channel' => 'solver'],
            );
            return null;
        }
    }

    /**
     * Нормализует адрес прокси в формат http://user:pass@host:port.
     *
     * БД хранит прокси в формате host:port@user:pass,
     * а solver ожидает http://user:pass@host:port.
     */
    private function normalizeProxy(string $proxy): string
    {
        // Уже в правильном формате (содержит схему)
        if (str_contains($proxy, '://')) {
            return $proxy;
        }

        // Формат БД: host:port@user:pass → http://user:pass@host:port
        if (preg_match('/^([^@]+)@(.+)$/', $proxy, $matches)) {
            $hostPort = $matches[1];
            $userPass = $matches[2];

            return sprintf('http://%s@%s', $userPass, $hostPort);
        }

        // Просто host:port без аутентификации
        return 'http://' . $proxy;
    }

    /**
     * Маскирует учётные данные в URL прокси для безопасного логирования.
     */
    private function maskProxy(string $proxy): string
    {
        $parsed = parse_url($proxy);
        if ($parsed === false || !isset($parsed['host'])) {
            return '***';
        }

        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
        $host = $parsed['host'];
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $auth = isset($parsed['user']) ? '***@' : '';

        return $scheme . $auth . $host . $port;
    }
}
