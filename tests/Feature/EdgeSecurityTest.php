<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Tests\TestCase;

class EdgeSecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    public function test_public_disk_stays_local_until_a_shared_driver_is_configured(): void
    {
        $this->assertSame('local', config('filesystems.disks.public.driver'));
    }

    public function test_spoofed_forwarded_headers_do_not_change_https_or_client_ip(): void
    {
        TrustProxies::flushState();
        $this->withoutVite();

        $response = $this->get('/admin/login', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '203.0.113.10',
        ]);

        $response->assertOk();
        $response->assertHeaderMissing('Strict-Transport-Security');
        $this->assertFalse(request()->isSecure());
        $this->assertNotSame('203.0.113.10', request()->ip());
    }

    public function test_a_trusted_proxy_enables_hsts_and_the_real_client_ip(): void
    {
        TrustProxies::at('*');
        $this->withoutVite();

        $response = $this->get('/admin/login', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '203.0.113.10',
        ]);

        $response->assertOk();
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $this->assertTrue(request()->isSecure());
        $this->assertSame('203.0.113.10', request()->ip());
    }
}
