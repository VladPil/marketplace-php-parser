<?php

declare(strict_types=1);

namespace Tests\Unit\Proxy;

use App\Module\Parser\Proxy\ProxySelection;
use PHPUnit\Framework\TestCase;

final class ProxySelectionTest extends TestCase
{
    public function testProxySelectionWithAddress(): void
    {
        $selection = new ProxySelection(
            address: 'http://proxy:8080',
            id: md5('http://proxy:8080'),
            sessionKey: 'http://proxy:8080',
        );

        $this->assertSame('http://proxy:8080', $selection->address);
        $this->assertFalse($selection->isDirect());
    }

    public function testDirectConnection(): void
    {
        $selection = new ProxySelection(
            address: null,
            id: 'direct',
            sessionKey: 'direct',
        );

        $this->assertNull($selection->address);
        $this->assertTrue($selection->isDirect());
    }

    public function testProxyIdIsUnique(): void
    {
        $proxy1 = new ProxySelection('http://proxy1:8080', md5('http://proxy1:8080'), 'http://proxy1:8080');
        $proxy2 = new ProxySelection('http://proxy2:8080', md5('http://proxy2:8080'), 'http://proxy2:8080');

        $this->assertNotSame($proxy1->id, $proxy2->id);
    }
}
