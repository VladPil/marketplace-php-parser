<?php

declare(strict_types=1);

namespace Tests\Unit\Proxy;

use App\Module\Parser\Config\HttpConfig;
use App\Module\Parser\Config\RedisConfig;
use App\Module\Parser\Proxy\RoundRobinRotator;
use App\Shared\Infrastructure\RedisPoolInterface;
use PHPUnit\Framework\TestCase;

final class RoundRobinRotatorTest extends TestCase
{
    private const array PROXIES = [
        'http://proxy1:8080',
        'http://proxy2:8080',
        'http://proxy3:8080',
        'http://proxy4:8080',
        'http://proxy5:8080',
    ];

    private function makeHttpConfig(
        array $proxies = self::PROXIES,
        bool $proxyEnabled = true,
    ): HttpConfig {
        return new HttpConfig(
            apiHost: 'api.example.com',
            apiPort: 443,
            ssl: true,
            connectionTimeoutSeconds: 10,
            requestTimeoutSeconds: 30,
            proxyList: empty($proxies) ? null : implode(',', $proxies),
            proxyEnabled: $proxyEnabled,
        );
    }

    private function makeRedisConfig(): RedisConfig
    {
        return new RedisConfig('localhost', 6379, 0, 5, 'mp:');
    }

    private function makeFakePool(object $fakeRedis): RedisPoolInterface
    {
        return new class($fakeRedis) implements RedisPoolInterface {
            public function __construct(private readonly object $redis) {}

            public function get(): mixed
            {
                return $this->redis;
            }

            public function put(mixed $redis): void {}

            public function drop(): void {}
        };
    }

    private function makeIncrementingRedis(): object
    {
        return new class {
            private int $counter = 0;

            public function incr(string $key): int
            {
                return ++$this->counter;
            }
        };
    }

    private function makeFailingRedis(): object
    {
        return new class {
            public function incr(string $key): int
            {
                throw new \RuntimeException('Redis недоступен');
            }
        };
    }

    public function testRoundRobinRotatesProxiesInOrder(): void
    {
        $rotator = new RoundRobinRotator(
            $this->makeHttpConfig(),
            $this->makeFakePool($this->makeIncrementingRedis()),
            $this->makeRedisConfig(),
        );

        for ($i = 0; $i < 15; $i++) {
            $selection = $rotator->selectProxy();
            // counter = i+1, index = (i+1) % 5
            $expectedProxy = self::PROXIES[($i + 1) % 5];
            $this->assertSame($expectedProxy, $selection->address, "Итерация {$i}");
            $this->assertSame(md5($expectedProxy), $selection->id);
            $this->assertSame($expectedProxy, $selection->sessionKey);
        }
    }

    public function testRedisUnavailableFallsBackToArrayRand(): void
    {
        $rotator = new RoundRobinRotator(
            $this->makeHttpConfig(),
            $this->makeFakePool($this->makeFailingRedis()),
            $this->makeRedisConfig(),
        );

        $selection = $rotator->selectProxy();

        $this->assertNotNull($selection->address);
        $this->assertContains($selection->address, self::PROXIES);
        $this->assertFalse($selection->isDirect());
    }

    public function testProxyDisabledReturnsDirect(): void
    {
        $rotator = new RoundRobinRotator(
            $this->makeHttpConfig(proxyEnabled: false),
            $this->makeFakePool($this->makeIncrementingRedis()),
            $this->makeRedisConfig(),
        );

        $selection = $rotator->selectProxy();

        $this->assertNull($selection->address);
        $this->assertSame('direct', $selection->id);
        $this->assertSame('direct', $selection->sessionKey);
        $this->assertTrue($selection->isDirect());
    }

    public function testEmptyProxyListReturnsDirect(): void
    {
        $rotator = new RoundRobinRotator(
            $this->makeHttpConfig(proxies: []),
            $this->makeFakePool($this->makeIncrementingRedis()),
            $this->makeRedisConfig(),
        );

        $selection = $rotator->selectProxy();

        $this->assertNull($selection->address);
        $this->assertSame('direct', $selection->id);
        $this->assertSame('direct', $selection->sessionKey);
        $this->assertTrue($selection->isDirect());
    }

    public function testRecordSuccessAndFailureAreNoOps(): void
    {
        $rotator = new RoundRobinRotator(
            $this->makeHttpConfig(),
            $this->makeFakePool($this->makeIncrementingRedis()),
            $this->makeRedisConfig(),
        );

        $rotator->recordSuccess('proxy-id');
        $rotator->recordFailure('proxy-id');

        $this->assertTrue(true);
    }
}
