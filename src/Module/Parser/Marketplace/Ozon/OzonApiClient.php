<?php

declare(strict_types=1);

namespace App\Module\Parser\Marketplace\Ozon;

use App\Module\Parser\Config\HttpConfig;
use App\Module\Parser\Config\RetryConfig;
use App\Shared\Contract\MarketplaceApiClientInterface;
use App\Shared\Contract\SessionManagerInterface;
use App\Shared\Contract\SolverClientInterface;
use App\Shared\DTO\SessionData;
use App\Shared\Logging\ParseLogger;
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
 * Гибридная схема запросов:
 * 1. Есть закешированные cookies → Guzzle (многопоток через Swoole воркеры)
 * 2. Нет cookies или 403 → solver browser fetch (получает данные + cookies)
 * 3. Кешируем cookies → следующие запросы снова через Guzzle
 *
 * Это обеспечивает баланс между обходом TLS fingerprint (первый запрос
 * через реальный браузер) и производительностью (параллельные Guzzle-запросы).
 */
final class OzonApiClient implements MarketplaceApiClientInterface
{
    private Client $client;

    public function __construct(
        private readonly HttpConfig $httpConfig,
        private readonly RetryConfig $retryConfig,
        private readonly OzonHeadersProvider $headersProvider,
        private readonly SessionManagerInterface $sessionManager,
        private readonly SolverClientInterface $solverClient,
        private readonly ParseLogger $logger,
    ) {
        $this->client = $this->createClient();
    }

    /**
     * Выполняет GET-запрос к API Ozon.
     *
     * Стратегия:
     * 1. Если есть кешированная сессия → Guzzle-запрос
     * 2. Если 403 или нет сессии → browser fetch через solver
     * 3. Browser fetch возвращает данные + cookies → кешируем
     *
     * @param string $path Путь API
     * @param array $queryParams GET-параметры
     * @return array Декодированный JSON-ответ
     */
    public function fetchPage(string $path, array $queryParams = []): array
    {
        $proxy = $this->httpConfig->getRandomProxy();
        $proxyKey = $proxy ?? 'direct';

        // Пробуем через Guzzle если есть кешированная сессия
        $session = $this->sessionManager->getSession($proxyKey);
        if ($session !== null) {
            $this->logger->info(
                sprintf('Guzzle запрос: %s (прокси: %s)', $path, $proxyKey),
                ['channel' => 'api'],
            );
            $response = $this->doGuzzleRequest($path, $queryParams, $proxy, $session);
            $status = $response->getStatusCode();
            if ($status !== Response::HTTP_FORBIDDEN) {
                $body = $response->getBody()->getContents();
                $this->logger->info(
                    sprintf('Guzzle ответ: HTTP %d, размер=%d байт', $status, strlen($body)),
                    ['channel' => 'api'],
                );
                return json_decode($body, true) ?? [];
            }

            $this->logger->warning(
                sprintf('Guzzle получил HTTP %d на %s — переключаюсь на browser fetch', Response::HTTP_FORBIDDEN, $path),
                ['proxy' => $proxyKey, 'channel' => 'api'],
            );
            $this->sessionManager->invalidateSession($proxyKey);
        }

        // Нет сессии или 403 — идём через browser fetch
        return $this->fetchViaBrowser($path, $queryParams, $proxy, $proxyKey);
    }

    /**
     * Выполняет запрос через browser fetch solver-service.
     *
     * Получает и данные ответа, и cookies для кеширования.
     */
    private function fetchViaBrowser(string $path, array $queryParams, ?string $proxy, string $proxyKey): array
    {
        $fullUrl = $this->buildFullUrl($path, $queryParams);

        $this->logger->info(
            sprintf('Browser fetch: %s', $fullUrl),
            ['proxy' => $proxyKey, 'channel' => 'api'],
        );

        $fetchResult = $this->solverClient->fetch($fullUrl, $proxy);

        if ($fetchResult === null) {
            $this->logger->error(
                sprintf('Browser fetch не вернул данных для %s', $path),
                ['url' => $fullUrl, 'channel' => 'api'],
            );
            return [];
        }

        // Кешируем cookies из ответа fetch для следующих Guzzle-запросов
        $fetchSession = $fetchResult['_session'] ?? null;
        if ($fetchSession instanceof SessionData) {
            $this->sessionManager->cacheSession($proxyKey, $fetchSession);
            $this->logger->info(
                sprintf('Cookies от browser fetch закешированы (%d cookies)', count($fetchSession->cookies)),
                ['proxy' => $proxyKey, 'channel' => 'solver'],
            );
        }

        // Возвращаем данные API (без метаданных _session)
        unset($fetchResult['_session']);

        return $fetchResult;
    }

    /**
     * Выполняет HTTP GET-запрос через Guzzle с cookies из кеша.
     */
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

        return $this->client->get($path, $options);
    }

    /**
     * Строит CookieJar из данных сессии solver.
     */
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

    /**
     * Ищет товары по текстовому запросу.
     */
    public function searchProducts(string $query, int $page = 1): array
    {
        return $this->fetchPage('/api/entrypoint-api.bx/page/json/v2', [
            'url' => '/search/?text=' . urlencode($query) . '&page=' . $page,
        ]);
    }

    /**
     * Загружает данные конкретного товара (API page 2).
     */
    public function fetchProduct(string $slug, int $externalId): array
    {
        $url = sprintf('/product/%s-%d/', $slug, $externalId);

        return $this->fetchPage('/api/entrypoint-api.bx/page/json/v2', [
            'url' => $url,
        ]);
    }

    /**
     * Загружает HTML-страницу товара (SSR page 1).
     *
     * Содержит Schema.org JSON-LD, галерею, категории —
     * данные, которых нет в API-ответе page 2.
     */
    public function fetchProductHtml(string $slug, int $externalId): string
    {
        $proxy = $this->httpConfig->getRandomProxy();
        $proxyKey = $proxy ?? 'direct';

        $productPath = $slug !== ''
            ? sprintf('/product/%s-%d/', $slug, $externalId)
            : sprintf('/product/%d/', $externalId);

        $fullUrl = sprintf('https://www.ozon.ru%s', $productPath);

        $this->logger->info(
            sprintf('Загрузка HTML страницы товара: %s', $fullUrl),
            ['proxy' => $proxyKey, 'channel' => 'api'],
        );

        $fetchResult = $this->solverClient->fetchHtml($fullUrl, $proxy);

        if ($fetchResult === null) {
            throw new \RuntimeException(sprintf(
                'Solver не смог загрузить HTML товара %d (URL: %s). Возможно, Ozon заблокировал запрос (403)',
                $externalId,
                $fullUrl,
            ));
        }

        // Кешируем cookies из успешного HTML-ответа для последующих Guzzle-запросов
        $fetchSession = $fetchResult['session'] ?? null;
        if ($fetchSession instanceof SessionData) {
            $this->sessionManager->cacheSession($proxyKey, $fetchSession);
            $this->logger->info(
                sprintf('Cookies от HTML fetch закешированы (%d cookies)', count($fetchSession->cookies)),
                ['proxy' => $proxyKey, 'channel' => 'solver'],
            );
        }

        return $fetchResult['html'];
    }

    /**
     * Загружает отзывы для товара.
     *
     * @deprecated Используйте fetchReviewsFirstPage() + fetchReviewsByNextPage() для курсорной пагинации
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

    /**
     * Загружает первую страницу отзывов товара.
     *
     * Формирует URL с tab=reviews и layout_container=reviewshelfpaginator
     * для получения первой страницы и курсора nextPage.
     */
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

    /**
     * Загружает следующую страницу отзывов по курсору nextPage.
     */
    public function fetchReviewsByNextPage(string $nextPageUrl): array
    {
        return $this->fetchPage('/api/entrypoint-api.bx/page/json/v2', [
            'url' => $nextPageUrl,
        ]);
    }

    /**
     * Собирает полный URL из пути и query-параметров.
     */
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

    /**
     * Создаёт Guzzle-клиент с retry middleware.
     */
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

    /**
     * Повторяет при: исключениях, HTTP 429, 500, 502, 503, 504.
     * НЕ повторяет 403 — обрабатывается в fetchPage через browser fetch.
     */
    private function createRetryDecider(): callable
    {
        return function (int $retries, RequestInterface $request, ?ResponseInterface $response, ?\Throwable $exception): bool {
            if ($retries >= $this->retryConfig->maxRetries) {
                return false;
            }

            if ($exception !== null) {
                return true;
            }

            if ($response !== null) {
                $status = $response->getStatusCode();
                return in_array($status, [
                    Response::HTTP_TOO_MANY_REQUESTS,
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                    Response::HTTP_BAD_GATEWAY,
                    Response::HTTP_SERVICE_UNAVAILABLE,
                    Response::HTTP_GATEWAY_TIMEOUT,
                ], true);
            }

            return false;
        };
    }

    /**
     * Экспоненциальная задержка с jitter.
     */
    private function createRetryDelay(): callable
    {
        return function (int $retries): int {
            $delay = $this->retryConfig->baseDelaySeconds * (2 ** $retries);
            $delay = min($delay, $this->retryConfig->maxDelaySeconds);

            $jitter = $delay * $this->retryConfig->jitterFactor;
            $delay += (mt_rand() / mt_getrandmax() * 2 - 1) * $jitter;

            return (int) ($delay * 1000);
        };
    }
}
