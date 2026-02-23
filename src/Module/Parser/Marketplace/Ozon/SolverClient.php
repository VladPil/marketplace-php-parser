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
            $payload = ['url' => $url];
            if ($proxy !== null) {
                $payload['proxy'] = $proxy;
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

    public function fetch(string $url, ?string $proxy = null): ?array
    {
        $traceId = TraceContext::getTraceId();

        $this->logger->info(
            sprintf('Browser fetch через solver: %s', $url),
            ['url' => $url, 'channel' => 'solver'],
        );

        try {
            $payload = ['url' => $url];
            if ($proxy !== null) {
                $payload['proxy'] = $proxy;
            }

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

    public function fetchHtml(string $url, ?string $proxy = null): ?array
    {
        $traceId = TraceContext::getTraceId();

        $this->logger->info(
            sprintf('Browser fetch HTML через solver: %s', $url),
            ['url' => $url, 'channel' => 'solver'],
        );

        try {
            $payload = ['url' => $url];
            if ($proxy !== null) {
                $payload['proxy'] = $proxy;
            }

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
