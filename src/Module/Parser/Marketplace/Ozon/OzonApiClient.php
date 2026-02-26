<?php

declare(strict_types=1);

namespace App\Module\Parser\Marketplace\Ozon;

use App\Module\Parser\Config\HttpConfig;
use App\Module\Parser\Config\RetryConfig;
use App\Module\Parser\Identity\Identity;
use App\Module\Parser\Identity\IdentityBlockedException;
use App\Module\Parser\Identity\IdentityPoolConfig;
use App\Shared\Contract\MarketplaceApiClientInterface;
use App\Shared\Contract\SolverClientInterface;
use App\Shared\DTO\SessionData;
use App\Shared\Logging\ParseLogger;
use App\Shared\Tracing\TraceContext;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP-клиент для взаимодействия с API Ozon.
 *
 * Работает исключительно через Identity Pool: identity из TraceContext → фиксированный прокси + cookies на всю задачу.
 * Запросы без identity не поддерживаются — будет выброшено исключение.
 */
final class OzonApiClient implements MarketplaceApiClientInterface
{
    private Client $client;

    public function __construct(
        private readonly HttpConfig $httpConfig,
        private readonly RetryConfig $retryConfig,
        private readonly OzonHeadersProvider $headersProvider,
        private readonly SolverClientInterface $solverClient,
        private readonly ParseLogger $logger,
        private readonly IdentityPoolConfig $identityPoolConfig,
    ) {
        $this->client = $this->createClient();
    }

    public function fetchPage(string $path, array $queryParams = []): array
    {
        $identity = TraceContext::getIdentity();
        if ($identity === null) {
            throw new \RuntimeException('Identity не установлена в TraceContext — запросы без identity не поддерживаются');
        }

        return $this->fetchPageWithIdentity($path, $queryParams, $identity);
    }

    public function fetchProductHtml(string $slug, int $externalId): string
    {
        $identity = TraceContext::getIdentity();
        if ($identity === null) {
            throw new \RuntimeException('Identity не установлена в TraceContext — запросы без identity не поддерживаются');
        }

        return $this->fetchProductHtmlWithIdentity($slug, $externalId, $identity);
    }

    // ─── Identity Pool ─────────────────────────────────────────────────

    private function fetchPageWithIdentity(string $path, array $queryParams, Identity $identity): array
    {
        // Проверяем перегрев identity (N подряд Guzzle 403 → ротация)
        if ($identity->isOverheated($this->identityPoolConfig->guzzle403Threshold)) {
            $this->logger->warning(
                sprintf(
                    'Identity %s перегрета (%d подряд 403, порог %d) — принудительная ротация',
                    substr($identity->id, 0, 8),
                    $identity->getGuzzle403Count(),
                    $this->identityPoolConfig->guzzle403Threshold,
                ),
                ['channel' => 'api'],
            );
            throw new IdentityBlockedException(sprintf(
                'Identity %s перегрета: %d подряд 403 (порог %d)',
                substr($identity->id, 0, 8),
                $identity->getGuzzle403Count(),
                $this->identityPoolConfig->guzzle403Threshold,
            ));
        }

        $proxy = $identity->proxyAddress;
        $session = $identity->getSession();

        if ($session !== null) {
            try {
                $response = $this->doGuzzleRequest($path, $queryParams, $proxy, $session);
                $status = $response->getStatusCode();

                if ($status !== Response::HTTP_FORBIDDEN) {
                    $body = $response->getBody()->getContents();
                    $identity->resetGuzzle403();
                    $this->logger->info(
                        sprintf('Guzzle ответ: HTTP %d, размер=%d байт (identity %s)', $status, strlen($body), substr($identity->id, 0, 8)),
                        ['channel' => 'api'],
                    );
                    $this->sleepRequestDelay();
                    return json_decode($body, true) ?? [];
                }

                // Guzzle 403 — инкрементируем счётчик
                $identity->incrementGuzzle403();
                $this->logger->warning(
                    sprintf(
                        'Guzzle 403 на %s (identity %s, серия 403: %d/%d) — пробуем browser fetch',
                        $path,
                        substr($identity->id, 0, 8),
                        $identity->getGuzzle403Count(),
                        $this->identityPoolConfig->guzzle403Threshold,
                    ),
                    ['channel' => 'api'],
                );
            } catch (IdentityBlockedException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf('Guzzle exception на %s (identity %s): %s — browser fetch', $path, substr($identity->id, 0, 8), $e->getMessage()),
                    ['channel' => 'api'],
                );
            }
        }

        $fullUrl = $this->buildFullUrl($path, $queryParams);
        $fetchResult = $this->solverClient->fetch($fullUrl, $proxy, $session);

        if ($fetchResult === null) {
            throw new IdentityBlockedException(sprintf(
                'Browser fetch не вернул данных для %s через прокси %s',
                $path,
                $identity->maskedProxy(),
            ));
        }

        $fetchSession = $fetchResult['_session'] ?? null;
        if ($fetchSession instanceof SessionData) {
            $identity->updateSession($fetchSession);
        }

        $this->sleepRequestDelay();
        unset($fetchResult['_session']);
        return $fetchResult;
    }

    private function fetchProductHtmlWithIdentity(string $slug, int $externalId, Identity $identity): string
    {
        $proxy = $identity->proxyAddress;
        $session = $identity->getSession();
        $productPath = $slug !== ''
            ? sprintf('/product/%s-%d/', $slug, $externalId)
            : sprintf('/product/%d/', $externalId);
        $fullUrl = sprintf('https://www.ozon.ru%s', $productPath);

        $this->logger->info(
            sprintf('HTML fetch: %s (identity %s)', $fullUrl, substr($identity->id, 0, 8)),
            ['channel' => 'api'],
        );

        // Сначала пробуем прямой Guzzle-запрос — параллельно, без блокировки solver
        if ($session !== null) {
            try {
                $response = $this->doGuzzleHtmlRequest($fullUrl, $proxy, $session);
                $status = $response->getStatusCode();

                if ($status !== Response::HTTP_FORBIDDEN) {
                    $html = $response->getBody()->getContents();

                    if ($html !== '' && strlen($html) > 1000) {
                        $this->logger->info(
                            sprintf('Guzzle HTML ответ: HTTP %d, %d байт (identity %s)', $status, strlen($html), substr($identity->id, 0, 8)),
                            ['channel' => 'api'],
                        );
                        return $html;
                    }

                    $this->logger->warning(
                        sprintf('Guzzle HTML пустой/короткий (%d байт, HTTP %d) — пробуем solver (identity %s)', strlen($html), $status, substr($identity->id, 0, 8)),
                        ['channel' => 'api'],
                    );
                } else {
                    $this->logger->warning(
                        sprintf('Guzzle HTML 403 на %s (identity %s) — пробуем solver', $fullUrl, substr($identity->id, 0, 8)),
                        ['channel' => 'api'],
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf('Guzzle HTML exception (identity %s): %s — пробуем solver', substr($identity->id, 0, 8), $e->getMessage()),
                    ['channel' => 'api'],
                );
            }
        }

        // Fallback: загрузка через solver (браузер, последовательно через _fetch_lock)
        $fetchResult = $this->solverClient->fetchHtml($fullUrl, $proxy, $session);
        if ($fetchResult === null) {
            throw new IdentityBlockedException(sprintf(
                'Solver не смог загрузить HTML товара %d (identity %s, прокси: %s)',
                $externalId,
                substr($identity->id, 0, 8),
                $identity->maskedProxy(),
            ));
        }
        $fetchSession = $fetchResult['session'] ?? null;
        if ($fetchSession instanceof SessionData && count($fetchSession->cookies) > 0) {
            $identity->updateSession($fetchSession);
            $this->logger->info(
                sprintf('Identity %s обновлена (%d cookies от HTML fetch через solver)', substr($identity->id, 0, 8), count($fetchSession->cookies)),
                ['channel' => 'solver'],
            );
        }
        return $fetchResult['html'];
    }

    // ─── Общие методы ──────────────────────────────────────────────────

    private function doGuzzleRequest(
        string $path,
        array $queryParams,
        ?string $proxy,
        SessionData $session,
    ): ResponseInterface {
        $this->headersProvider->setSessionData($session);

        $options = [
            'query' => $queryParams,
            'headers' => $this->headersProvider->getHeaders(),
            'cookies' => $this->buildCookieJar($session),
        ];

        if ($proxy !== null) {
            $options['proxy'] = $proxy;
        }

        // Debug-лог: заголовки, cookies, прокси для сравнения с solver-запросами
        $this->logger->debug(
            sprintf('[guzzle-debug] API-запрос: %s', $path),
            [
                'headers' => $options['headers'] ?? [],
                'cookie_names' => array_map(
                    static fn(array $c): string => $c['name'],
                    $session->cookies,
                ),
                'cookie_count' => count($session->cookies),
                'proxy' => $proxy !== null ? $this->maskProxy($proxy) : 'direct',
                'user_agent' => $session->userAgent,
                'identity_id' => TraceContext::getIdentity()?->id ? substr(TraceContext::getIdentity()->id, 0, 8) : null,
                'channel' => 'api',
            ],
        );

        return $this->client->get($path, $options);
    }

    /**
     * Guzzle-запрос для загрузки HTML страницы по полному URL.
     *
     * Использует заголовки для HTML-навигации (Accept: text/html, Sec-Fetch-Dest: document)
     * вместо API-заголовков (Accept: application/json, Sec-Fetch-Dest: empty).
     */
    private function doGuzzleHtmlRequest(
        string $fullUrl,
        ?string $proxy,
        SessionData $session,
    ): ResponseInterface {
        $this->headersProvider->setSessionData($session);
        $headers = $this->headersProvider->getHeaders();

        // Переопределяем заголовки для HTML-навигации вместо API-запроса
        $headers['Accept'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';
        $headers['Sec-Fetch-Dest'] = 'document';
        $headers['Sec-Fetch-Mode'] = 'navigate';
        $headers['Sec-Fetch-Site'] = 'none';
        $headers['Sec-Fetch-User'] = '?1';
        $headers['Upgrade-Insecure-Requests'] = '1';
        unset($headers['Content-Type'], $headers['content-type']);
        // Убираем Ozon API-специфичные заголовки — они не нужны для HTML-навигации
        unset(
            $headers['x-o3-app-name'],
            $headers['x-o3-app-version'],
            $headers['x-o3-manifest-version'],
            $headers['x-page-view-id'],
            $headers['x-page-previous'],
            $headers['x-o3-parent-requestid'],
        );

        $options = [
            'headers' => $headers,
            'cookies' => $this->buildCookieJar($session),
        ];

        if ($proxy !== null) {
            $options['proxy'] = $proxy;
        }

        // Debug-лог: заголовки, cookies, прокси для HTML-запроса
        $this->logger->debug(
            sprintf('[guzzle-debug] HTML-запрос: %s', $fullUrl),
            [
                'headers' => $options['headers'] ?? [],
                'cookie_names' => array_map(
                    static fn(array $c): string => $c['name'],
                    $session->cookies,
                ),
                'cookie_count' => count($session->cookies),
                'proxy' => $proxy !== null ? $this->maskProxy($proxy) : 'direct',
                'user_agent' => $session->userAgent,
                'identity_id' => TraceContext::getIdentity()?->id ? substr(TraceContext::getIdentity()->id, 0, 8) : null,
                'channel' => 'api',
            ],
        );

        return $this->client->get($fullUrl, $options);
    }

    private function buildCookieJar(SessionData $session): CookieJar
    {
        $jar = new CookieJar();

        foreach ($session->cookies as $cookie) {
            $jar->setCookie(new SetCookie([
                'Name' => $cookie['name'],
                'Value' => $cookie['value'],
                'Domain' => $cookie['domain'],
                'Path' => $cookie['path'],
            ]));
        }

        return $jar;
    }

    public function fetchProduct(string $slug, int $externalId): array
    {
        $url = sprintf('/product/%s-%d/', $slug, $externalId);

        return $this->fetchPage('/api/entrypoint-api.bx/page/json/v2', [
            'url' => $url,
        ]);
    }

    /**
     * @deprecated Используйте fetchReviewsFirstPage() + fetchReviewsByNextPage()
     */
    public function fetchReviews(string $slug, int $externalId, int $page = 1): array
    {
        $productPath = $slug !== ''
            ? sprintf('/product/%s-%d/', $slug, $externalId)
            : sprintf('/product/%d/', $externalId);

        return $this->fetchPage('/api/entrypoint-api.bx/page/json/v2', [
            'url' => $productPath,
            'layout_container' => 'reviewshelfpaginator',
            'layout_page_index' => $page + 2,
            'page' => $page,
        ]);
    }

    public function fetchReviewsFirstPage(string $slug, int $externalId): array
    {
        $productPath = $slug !== ''
            ? sprintf('/product/%s-%d/', $slug, $externalId)
            : sprintf('/product/%d/', $externalId);

        return $this->fetchPage('/api/entrypoint-api.bx/page/json/v2', [
            'url' => $productPath,
            'layout_container' => 'reviewshelfpaginator',
            'layout_page_index' => 3,
            'tab' => 'reviews',
            'sort' => 'published_at_desc',
        ]);
    }

    public function fetchReviewsByNextPage(string $nextPageUrl): array
    {
        return $this->fetchPage('/api/entrypoint-api.bx/page/json/v2', [
            'url' => $nextPageUrl,
        ]);
    }

    private function buildFullUrl(string $path, array $queryParams): string
    {
        $scheme = $this->httpConfig->ssl ? 'https' : 'http';
        $baseUrl = sprintf('%s://%s', $scheme, $this->httpConfig->apiHost);

        if (!($this->httpConfig->ssl && $this->httpConfig->apiPort === 443)) {
            $baseUrl .= ':' . $this->httpConfig->apiPort;
        }

        $url = $baseUrl . $path;

        if ($queryParams !== []) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $url;
    }

    private function createClient(): Client
    {
        $stack = HandlerStack::create();

        $stack->push(Middleware::retry(
            $this->createRetryDecider(),
            $this->createRetryDelay(),
        ));

        $scheme = $this->httpConfig->ssl ? 'https' : 'http';

        return new Client([
            'handler' => $stack,
            'base_uri' => sprintf('%s://%s:%d', $scheme, $this->httpConfig->apiHost, $this->httpConfig->apiPort),
            'timeout' => $this->httpConfig->requestTimeoutSeconds,
            'connect_timeout' => $this->httpConfig->connectionTimeoutSeconds,
            'http_errors' => false,
        ]);
    }

    private function createRetryDecider(): callable
    {
        return function (int $retries, RequestInterface $request, ?ResponseInterface $response, ?\Throwable $exception): bool {
            if ($retries >= $this->retryConfig->maxRetries) {
                if ($exception !== null) {
                    $this->logger->error(
                        sprintf('[retry] Все %d попыток исчерпаны для %s: %s', $retries, $request->getUri()->getPath(), $exception->getMessage()),
                        ['channel' => 'api'],
                    );
                }
                return false;
            }

            $shouldRetry = false;
            $reason = '';

            if ($exception !== null) {
                $shouldRetry = true;
                $reason = $exception->getMessage();
            } elseif ($response !== null) {
                $status = $response->getStatusCode();
                $shouldRetry = in_array($status, [
                    Response::HTTP_TOO_MANY_REQUESTS,
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                    Response::HTTP_BAD_GATEWAY,
                    Response::HTTP_SERVICE_UNAVAILABLE,
                    Response::HTTP_GATEWAY_TIMEOUT,
                ], true);
                if ($shouldRetry) {
                    $reason = sprintf('HTTP %d', $status);
                }
            }

            if ($shouldRetry) {
                $delay = $this->calculateDelay($retries);
                $this->logger->warning(
                    sprintf(
                        '[retry] Попытка %d/%d для %s — %s, повтор через %.1fс',
                        $retries + 1,
                        $this->retryConfig->maxRetries,
                        $request->getUri()->getPath(),
                        $reason,
                        $delay / 1000,
                    ),
                    ['channel' => 'api'],
                );
            }

            return $shouldRetry;
        };
    }

    private function createRetryDelay(): callable
    {
        return fn(int $retries): int => $this->calculateDelay($retries);
    }

    /**
     * Exponential backoff + jitter.
     */
    private function calculateDelay(int $retries): int
    {
        $delay = $this->retryConfig->baseDelaySeconds * (2 ** $retries);
        $delay = min($delay, $this->retryConfig->maxDelaySeconds);

        $jitter = $delay * $this->retryConfig->jitterFactor;
        $delay += (mt_rand() / mt_getrandmax() * 2 - 1) * $jitter;

        return max((int) ($delay * 1000), 1000);
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

    /**
     * Задержка между API-запросами для предотвращения rate limiting.
     * Swoole-совместимый sleep (корутина не блокирует event loop).
     */
    private function sleepRequestDelay(): void
    {
        $delayMs = $this->identityPoolConfig->requestDelayMs;
        if ($delayMs <= 0) {
            return;
        }

        $seconds = $delayMs / 1000;

        if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::sleep($seconds);
        } else {
            usleep((int) ($seconds * 1_000_000));
        }
    }

}
