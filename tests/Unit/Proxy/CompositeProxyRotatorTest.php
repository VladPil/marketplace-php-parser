<?php

declare(strict_types=1);

namespace Tests\Unit\Proxy;

use App\Module\Parser\Config\HttpConfig;
use App\Module\Parser\Proxy\CircuitBreakerProxyFilterInterface;
use App\Module\Parser\Proxy\CompositeProxyRotator;
use App\Module\Parser\Proxy\Config\ProxyRotationConfig;
use App\Module\Parser\Proxy\ProxyHealthTrackerInterface;
use App\Module\Parser\Proxy\ProxyProvider;
use App\Module\Parser\Proxy\ProxySelection;
use App\Module\Parser\Proxy\RoundRobinRotator;
use App\Module\Parser\Queue\RedisConnectionPool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * CompositeProxyRotator собирает цепочку Sticky → HealthAware → RoundRobin.
 * CircuitBreaker и HealthTracker мокаются через интерфейсы.
 */
final class CompositeProxyRotatorTest extends TestCase
{
    private const array PROXIES = [
        'http://proxy1:8080',
        'http://proxy2:8080',
    ];

    private function makeHttpConfig(bool $proxyEnabled = true): HttpConfig
    {
        return new HttpConfig(
            apiHost: 'api.example.com',
            apiPort: 443,
            ssl: true,
            connectionTimeoutSeconds: 10,
            requestTimeoutSeconds: 30,
            proxyList: implode(',', self::PROXIES),
            proxyEnabled: $proxyEnabled,
        );
    }

    private function makeRotationConfig(): ProxyRotationConfig
    {
        return new ProxyRotationConfig(
            circuitBreakerThreshold: 3,
            circuitBreakerTimeoutSeconds: 30,
            stickySessionTtlSeconds: 300,
        );
    }

    private function makeUninitializedPool(): RedisConnectionPool
    {
        $reflection = new \ReflectionClass(RedisConnectionPool::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    private function makeLogger(): NullLogger
    {
        return new NullLogger();
    }

    private function makeCircuitBreaker(bool $available = true): CircuitBreakerProxyFilterInterface
    {
        $cb = $this->createMock(CircuitBreakerProxyFilterInterface::class);
        $cb->method('isAvailable')->willReturn($available);
        return $cb;
    }

    /**
     * Создаёт ProxyProvider, где rotating-прокси определяется через getAll().
     *
     * ProxyProvider — final, поэтому мокаем через реальный экземпляр с подготовленными данными.
     */
    private function makeProxyProviderWithRotating(string $rotatingAddress): ProxyProvider
    {
        $httpConfig = new HttpConfig(
            apiHost: 'api.example.com',
            apiPort: 443,
            ssl: true,
            connectionTimeoutSeconds: 10,
            requestTimeoutSeconds: 30,
            proxyList: '',
            proxyEnabled: true,
        );

        $proxyRepository = $this->createMock(\App\Shared\Repository\ProxyRepository::class);
        $proxyEntity = new \App\Shared\Entity\Proxy($rotatingAddress, 'admin', 'rotating');
        $proxyRepository->method('findAllEnabled')->willReturn([$proxyEntity]);

        return new ProxyProvider($httpConfig, $proxyRepository);
    }

    public function testSelectProxyReturnsProxyWhenCbAvailable(): void
    {
        $healthTracker = $this->createMock(ProxyHealthTrackerInterface::class);
        $healthTracker->method('isHealthy')->willReturn(true);

        $pool = $this->makeUninitializedPool();
        $httpConfig = $this->makeHttpConfig();
        $rotationConfig = $this->makeRotationConfig();

        $roundRobin = $this->createMock(RoundRobinRotator::class);
        $proxyId = md5('http://proxy1:8080');
        $roundRobin->method('selectProxy')
            ->willReturn(new ProxySelection('http://proxy1:8080', $proxyId, 'http://proxy1:8080'));

        $composite = new CompositeProxyRotator(
            $httpConfig,
            $pool,
            $rotationConfig,
            $this->makeCircuitBreaker(),
            $healthTracker,
            $this->makeLogger(),
            $roundRobin,
        );

        $selection = $composite->selectProxy();
        $this->assertFalse($selection->isDirect());
    }

    public function testRecordSuccessDelegatesToHealthAndCb(): void
    {
        $healthTracker = $this->createMock(ProxyHealthTrackerInterface::class);
        $healthTracker->expects($this->once())->method('recordSuccess')->with('proxy-id');

        $composite = new CompositeProxyRotator(
            $this->makeHttpConfig(),
            $this->makeUninitializedPool(),
            $this->makeRotationConfig(),
            $this->makeCircuitBreaker(),
            $healthTracker,
            $this->makeLogger(),
            $this->createMock(RoundRobinRotator::class),
        );

        $composite->recordSuccess('proxy-id');
    }

    public function testRecordFailureDelegatesToHealthAndCb(): void
    {
        $healthTracker = $this->createMock(ProxyHealthTrackerInterface::class);
        $healthTracker->expects($this->once())->method('recordFailure')->with('proxy-id');

        $composite = new CompositeProxyRotator(
            $this->makeHttpConfig(),
            $this->makeUninitializedPool(),
            $this->makeRotationConfig(),
            $this->makeCircuitBreaker(),
            $healthTracker,
            $this->makeLogger(),
            $this->createMock(RoundRobinRotator::class),
        );

        $composite->recordFailure('proxy-id');
    }

    public function testProxyDisabledReturnsDirect(): void
    {
        $healthTracker = $this->createMock(ProxyHealthTrackerInterface::class);

        $roundRobin = $this->createMock(RoundRobinRotator::class);
        $roundRobin->method('selectProxy')
            ->willReturn(new ProxySelection(null, 'direct', 'direct'));

        $composite = new CompositeProxyRotator(
            $this->makeHttpConfig(proxyEnabled: false),
            $this->makeUninitializedPool(),
            $this->makeRotationConfig(),
            $this->makeCircuitBreaker(),
            $healthTracker,
            $this->makeLogger(),
            $roundRobin,
        );

        $selection = $composite->selectProxy();
        $this->assertTrue($selection->isDirect());
        $this->assertSame('direct', $selection->id);
    }

    public function testRecordFailureSkipsHealthAndCbForRotatingProxy(): void
    {
        $rotatingAddress = 'http://rotating-proxy:8080';
        $rotatingId = md5($rotatingAddress);

        $healthTracker = $this->createMock(ProxyHealthTrackerInterface::class);
        $healthTracker->expects($this->never())->method('recordFailure');

        $cb = $this->createMock(CircuitBreakerProxyFilterInterface::class);
        $cb->expects($this->never())->method('recordFailure');

        $composite = new CompositeProxyRotator(
            $this->makeHttpConfig(),
            $this->makeUninitializedPool(),
            $this->makeRotationConfig(),
            $cb,
            $healthTracker,
            $this->makeLogger(),
            $this->createMock(RoundRobinRotator::class),
            $this->makeProxyProviderWithRotating($rotatingAddress),
        );

        $composite->recordFailure($rotatingId);
    }

    public function testRecordSuccessSkipsHealthAndCbForRotatingProxy(): void
    {
        $rotatingAddress = 'http://rotating-proxy:8080';
        $rotatingId = md5($rotatingAddress);

        $healthTracker = $this->createMock(ProxyHealthTrackerInterface::class);
        $healthTracker->expects($this->never())->method('recordSuccess');

        $cb = $this->createMock(CircuitBreakerProxyFilterInterface::class);
        $cb->expects($this->never())->method('recordSuccess');

        $composite = new CompositeProxyRotator(
            $this->makeHttpConfig(),
            $this->makeUninitializedPool(),
            $this->makeRotationConfig(),
            $cb,
            $healthTracker,
            $this->makeLogger(),
            $this->createMock(RoundRobinRotator::class),
            $this->makeProxyProviderWithRotating($rotatingAddress),
        );

        $composite->recordSuccess($rotatingId);
    }

    public function testRecordFailureDelegatesToHealthAndCbForStaticProxy(): void
    {
        $rotatingAddress = 'http://rotating-proxy:8080';
        $staticId = md5('http://proxy1:8080'); // не rotating

        $healthTracker = $this->createMock(ProxyHealthTrackerInterface::class);
        $healthTracker->expects($this->once())->method('recordFailure')->with($staticId);

        $cb = $this->createMock(CircuitBreakerProxyFilterInterface::class);
        $cb->expects($this->once())->method('recordFailure')->with($staticId);

        $composite = new CompositeProxyRotator(
            $this->makeHttpConfig(),
            $this->makeUninitializedPool(),
            $this->makeRotationConfig(),
            $cb,
            $healthTracker,
            $this->makeLogger(),
            $this->createMock(RoundRobinRotator::class),
            $this->makeProxyProviderWithRotating($rotatingAddress),
        );

        $composite->recordFailure($staticId);
    }
}