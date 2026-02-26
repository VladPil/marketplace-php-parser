<?php

declare(strict_types=1);

namespace Tests\Unit\Proxy;

use App\Module\Parser\Config\HttpConfig;
use App\Module\Parser\Proxy\Config\ProxyRotationConfig;
use App\Module\Parser\Proxy\HealthAwareRotator;
use App\Module\Parser\Proxy\ProxyHealthTrackerInterface;
use App\Module\Parser\Proxy\ProxyRotatorInterface;
use App\Module\Parser\Proxy\ProxySelection;
use PHPUnit\Framework\TestCase;

final class HealthAwareRotatorTest extends TestCase
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

    public function testHealthyProxyPassesThrough(): void
    {
        $proxyId = md5('http://proxy1:8080');
        $innerSelection = new ProxySelection('http://proxy1:8080', $proxyId, 'http://proxy1:8080');

        $inner = $this->createMock(ProxyRotatorInterface::class);
        $inner->method('selectProxy')->willReturn($innerSelection);

        $healthTracker = $this->createMock(ProxyHealthTrackerInterface::class);
        $healthTracker->method('isHealthy')->with($proxyId)->willReturn(true);

        $rotator = new HealthAwareRotator($inner, $healthTracker, $this->makeHttpConfig());
        $selection = $rotator->selectProxy();

        $this->assertSame('http://proxy1:8080', $selection->address);
        $this->assertSame($proxyId, $selection->id);
    }

    public function testUnhealthyProxyFallsToOtherHealthy(): void
    {
        $proxyId1 = md5('http://proxy1:8080');
        $proxyId2 = md5('http://proxy2:8080');
        $innerSelection = new ProxySelection('http://proxy1:8080', $proxyId1, 'http://proxy1:8080');

        $inner = $this->createMock(ProxyRotatorInterface::class);
        $inner->method('selectProxy')->willReturn($innerSelection);

        $healthTracker = $this->createMock(ProxyHealthTrackerInterface::class);
        $healthTracker->method('isHealthy')
            ->willReturnCallback(fn(string $id) => $id !== $proxyId1);
        $healthTracker->method('filterHealthy')
            ->willReturn([$proxyId2]);

        $rotator = new HealthAwareRotator($inner, $healthTracker, $this->makeHttpConfig());
        $selection = $rotator->selectProxy();

        $this->assertSame('http://proxy2:8080', $selection->address);
        $this->assertSame($proxyId2, $selection->id);
    }

    public function testAllUnhealthyFallsToDirect(): void
    {
        $proxyId1 = md5('http://proxy1:8080');
        $innerSelection = new ProxySelection('http://proxy1:8080', $proxyId1, 'http://proxy1:8080');

        $inner = $this->createMock(ProxyRotatorInterface::class);
        $inner->method('selectProxy')->willReturn($innerSelection);

        $healthTracker = $this->createMock(ProxyHealthTrackerInterface::class);
        $healthTracker->method('isHealthy')->willReturn(false);
        $healthTracker->method('filterHealthy')->willReturn([]);

        $rotator = new HealthAwareRotator($inner, $healthTracker, $this->makeHttpConfig());
        $selection = $rotator->selectProxy();

        $this->assertTrue($selection->isDirect());
        $this->assertSame('direct', $selection->id);
    }

    public function testDirectSelectionPassesThrough(): void
    {
        $directSelection = new ProxySelection(null, 'direct', 'direct');

        $inner = $this->createMock(ProxyRotatorInterface::class);
        $inner->method('selectProxy')->willReturn($directSelection);

        $healthTracker = $this->createMock(ProxyHealthTrackerInterface::class);
        $healthTracker->expects($this->never())->method('isHealthy');

        $rotator = new HealthAwareRotator($inner, $healthTracker, $this->makeHttpConfig());
        $selection = $rotator->selectProxy();

        $this->assertTrue($selection->isDirect());
    }

    public function testRecordSuccessDelegates(): void
    {
        $inner = $this->createMock(ProxyRotatorInterface::class);
        $inner->expects($this->once())->method('recordSuccess')->with('proxy-id');

        $healthTracker = $this->createMock(ProxyHealthTrackerInterface::class);
        $healthTracker->expects($this->once())->method('recordSuccess')->with('proxy-id');

        $rotator = new HealthAwareRotator($inner, $healthTracker, $this->makeHttpConfig());
        $rotator->recordSuccess('proxy-id');
    }

    public function testRecordFailureDelegates(): void
    {
        $inner = $this->createMock(ProxyRotatorInterface::class);
        $inner->expects($this->once())->method('recordFailure')->with('proxy-id');

        $healthTracker = $this->createMock(ProxyHealthTrackerInterface::class);
        $healthTracker->expects($this->once())->method('recordFailure')->with('proxy-id');

        $rotator = new HealthAwareRotator($inner, $healthTracker, $this->makeHttpConfig());
        $rotator->recordFailure('proxy-id');
    }
}
