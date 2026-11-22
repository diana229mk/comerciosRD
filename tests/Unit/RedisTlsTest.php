<?php

namespace Tests\Unit;

use App\Support\RedisTls;
use PHPUnit\Framework\TestCase;

class RedisTlsTest extends TestCase
{
    public function test_tcp_connections_stay_unchanged(): void
    {
        $connection = ['host' => 'redis', 'port' => '6379'];

        $this->assertSame($connection, RedisTls::apply($connection, null, null));
        $this->assertSame($connection, RedisTls::apply($connection, 'tcp', '/secrets/ca.pem'));
        $this->assertArrayNotHasKey('scheme', RedisTls::apply($connection, 'tcp', null));
    }

    public function test_tls_verifies_the_server_and_can_pin_a_ca(): void
    {
        $secured = RedisTls::apply(['host' => 'private.db.ondigitalocean.com'], 'tls', '/secrets/redis-ca.pem');

        $this->assertSame('tls', $secured['scheme']);
        $this->assertTrue($secured['context']['stream']['verify_peer']);
        $this->assertTrue($secured['context']['stream']['verify_peer_name']);
        $this->assertSame('/secrets/redis-ca.pem', $secured['context']['stream']['cafile']);
    }

    public function test_tls_without_a_ca_does_not_disable_verification(): void
    {
        $secured = RedisTls::apply(['host' => 'redis.internal'], 'tls', '');

        $this->assertTrue($secured['context']['stream']['verify_peer']);
        $this->assertArrayNotHasKey('cafile', $secured['context']['stream']);
    }
}
