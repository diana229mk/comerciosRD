<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_security_headers_present_on_admin_login(): void
    {
        $this->withoutVite();

        config([
            'security.csp.enabled' => true,
            'security.headers.x_frame_options' => 'DENY',
            'security.headers.x_content_type_options' => 'nosniff',
        ]);

        $response = $this->get('/admin/login');

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-XSS-Protection', '1; mode=block');
        $this->assertTrue(
            $response->headers->has('Content-Security-Policy')
            || $response->headers->has('Content-Security-Policy-Report-Only')
        );
    }
}
