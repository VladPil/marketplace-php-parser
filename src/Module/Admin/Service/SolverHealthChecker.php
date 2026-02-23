<?php

declare(strict_types=1);

namespace App\Module\Admin\Service;

use Symfony\Component\HttpFoundation\Response;

/**
 * Сервис проверки здоровья solver-service.
 *
 * Выполняет HTTP-запрос к solver-service для проверки
 * что сервис запущен и готов обрабатывать запросы.
 */
final class SolverHealthChecker
{
    private string $solverUrl;

    public function __construct(
        string $solverHost = 'host.docker.internal',
        int $solverPort = 8204,
    ) {
        $this->solverUrl = sprintf('http://%s:%d', $solverHost, $solverPort);
    }

    /**
     * Проверяет доступность solver-service.
     *
     * @return array{available: bool, status: string, browser: bool|null, error: string|null}
     */
    public function check(): array
    {
        try {
            $ch = curl_init($this->solverUrl . '/health');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== Response::HTTP_OK || $response === false) {
                return [
                    'available' => false,
                    'status' => 'unavailable',
                    'browser' => null,
                    'error' => sprintf('HTTP %d', $httpCode),
                ];
            }

            $data = json_decode($response, true);
            return [
                'available' => true,
                'status' => $data['status'] ?? 'unknown',
                'browser' => $data['browser'] ?? null,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'available' => false,
                'status' => 'error',
                'browser' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Проверяет готовность solver-service (readiness probe).
     *
     * @return array{ready: bool, checks: array}
     */
    public function checkReadiness(): array
    {
        try {
            $ch = curl_init($this->solverUrl . '/health/ready');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== Response::HTTP_OK || $response === false) {
                return ['ready' => false, 'checks' => []];
            }

            $data = json_decode($response, true);
            return [
                'ready' => ($data['status'] ?? '') === 'ready',
                'checks' => $data['checks'] ?? [],
            ];
        } catch (\Throwable) {
            return ['ready' => false, 'checks' => []];
        }
    }
}
