<?php

declare(strict_types=1);

namespace App\Module\Parser\Identity;

/**
 * Конфигурация пула identity.
 */
final readonly class IdentityPoolConfig
{
    public function __construct(
        /** Максимальное время жизни identity в секундах */
        public int $identityTtlSeconds = 1200,
        /** Интервал между итерациями warmer (секунды) */
        public int $warmerIntervalSeconds = 30,
        /** Интервал между итерациями sanitizer (секунды) */
        public int $sanitizerIntervalSeconds = 5,
        /** Максимум попыток перезахвата identity при 403 */
        public int $maxIdentityRetries = 2,
        /** URL для прогрева identity (страница с полным набором cookies) */
        public string $warmupUrl = 'https://www.ozon.ru/product/balaklava-turn-off-on-new-965672325/',
        /** Задержка между API-запросами в миллисекундах (защита от rate limiting) */
        public int $requestDelayMs = 300,
        /** Порог последовательных Guzzle 403 для ротации identity */
        public int $guzzle403Threshold = 3,
    ) {}
}
