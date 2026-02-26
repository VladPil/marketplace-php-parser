<?php

declare(strict_types=1);

namespace Tests\Unit\Proxy;

use App\Module\Parser\Config\HttpConfig;
use App\Module\Parser\Proxy\Config\ProxyRotationConfig;
use App\Module\Parser\Proxy\ProxyRotatorInterface;
use App\Module\Parser\Proxy\ProxySelection;
use App\Module\Parser\Proxy\StickySessionRotator;
use App\Module\Parser\Queue\RedisConnectionPool;
use PHPUnit\Framework\TestCase;

/**
 * StickySessionRotator: RedisConnectionPool final + Swoole Channel = нельзя мокнуть.
 * Тестируем через Reflection (newInstanceWithoutConstructor) —
 * pool->get() бросит исключение → StickySessionRotator ловит \Throwable.
 */
final class StickySessionRotatorTest extends TestCase
{
    private const array PROXIES = [
        'http://proxy1:8080',
        'http://proxy2:8080',
    ];
    private function makeHttpConfig(): HttpConfig
    {
        return new HttpConfig(
            apiHost: 'api.example.com',
            apiPort: 443,
            ssl: true,
            connectionTimeoutSeconds: 10,
            requestTimeoutSeconds: 30,
            proxyList: implode(',', self::PROXIES),
            proxyEnabled: true,
        );
    }

    private function makeRotationConfig(): ProxyRotationConfig
    {
        return new ProxyRotationConfig(stickySessionTtlSeconds: 300);
    }

    private function makeUninitializedPool(): RedisConnectionPool
    {
        $reflection = new \ReflectionClass(RedisConnectionPool::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    public function testWithoutStickyKeyDelegatesDirectly(): void
    {
        $expected = new ProxySelection('http://proxy1:8080', md5('http://proxy1:8080'), 'http://proxy1:8080');

        $inner = $this->createMock(ProxyRotatorInterface::class);
        $inner->expects($this->once())->method('selectProxy')->with(null)->willReturn($expected);

        $rotator = new StickySessionRotator(
            $inner,
            $this->makeRotationConfig(),
            $this->makeHttpConfig(),
            $this->makeUninitializedPool(),
        );
        $selection = $rotator->selectProxy(null);
        $this->assertSame('http://proxy1:8080', $selection->address);
    }

    public function testStickyKeyFallsToInnerWhenRedisUnavailable(): void
    {
        $expected = new ProxySelection('http://proxy2:8080', md5('http://proxy2:8080'), 'http://proxy2:8080');

        $inner = $this->createMock(ProxyRotatorInterface::class);
        $inner->expects($this->exactly(2))
            ->method('selectProxy')
            ->with('task-123')
            ->willReturn($expected);

        $rotator = new StickySessionRotator(
            $inner,
            $this->makeRotationConfig(),
            $this->makeHttpConfig(),
            $this->makeUninitializedPool(),
        );
        $selection1 = $rotator->selectProxy('task-123');
        $this->assertSame('http://proxy2:8080', $selection1->address);
        $selection2 = $rotator->selectProxy('task-123');
        $this->assertSame('http://proxy2:8080', $selection2->address);
    }
    public function testRecordSuccessDelegatesToInner(): void
    {
        $inner = $this->createMock(ProxyRotatorInterface::class);
        $inner->expects($this->once())->method('recordSuccess')->with('proxy-id');
        $rotator = new StickySessionRotator(
            $inner,
            $this->makeRotationConfig(),
            $this->makeHttpConfig(),
            $this->makeUninitializedPool(),
        );
        $rotator->recordSuccess('proxy-id');
    }
    public function testRecordFailureDelegatesToInner(): void
    {
        $inner = $this->createMock(ProxyRotatorInterface::class);
        $inner->expects($this->once())->method('recordFailure')->with('proxy-id');
        $rotator = new StickySessionRotator(
            $inner,
            $this->makeRotationConfig(),
            $this->makeHttpConfig(),
            $this->makeUninitializedPool(),
        );
        $rotator->recordFailure('proxy-id');
    }
}