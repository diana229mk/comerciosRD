<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Services\AuthContextResolver;
use Tests\TestCase;

/**
 * Recorre pantallas reales (GET) y falla si hay 404/500.
 * No usa DatabaseTransactions: lee los comercios locales.
 */
class LiveSmoke404Test extends TestCase
{
    /**
     * @return list<string>
     */
    protected function tenantPages(): array
    {
        return [
            '',
            '/pos',
            '/preventa',
            '/products',
            '/products/create',
            '/product-categories',
            '/customers',
            '/customers/create',
            '/sales',
            '/quotes',
            '/quotes/create',
            '/orders',
            '/orders/create',
            '/cash-sessions',
            '/cash-sessions/create',
            '/expenses',
            '/expenses/create',
            '/suppliers',
            '/purchases',
            '/receivables',
            '/payables',
            '/reportes',
            '/reporte-itbis',
            '/reportes/sucursales',
            '/datos-del-negocio',
            '/change-password',
            '/usuarios',
            '/sucursales',
            '/inventory-movements',
            '/stock-counts',
            '/stock-transfers',
            '/prescriptions',
            '/pharmacy-dashboard',
            '/pharmacy-settings',
            '/libro-controlados',
            '/trazabilidad-lotes',
            '/dashboard-ferreteria',
            '/dashboard-cafeteria',
            '/cocina-salida',
            '/menu-qr',
            '/etiquetas-precio',
            '/print-batch-labels',
            '/product-kardex',
            '/reorder-alerts',
            '/alertas-inventario',
            '/reposicion-sugerida',
            '/rotacion-inventario',
            '/ia-insights',
            '/importacion-masiva',
            '/retail-variant-matrix',
            '/retail-collection-import',
            '/gift-cards',
            '/product-seasons',
            '/product-serials',
            '/product-locations',
            '/price-lists',
            '/special-orders',
            '/accounts',
            '/journal-entries',
            '/configuracion-ecf',
            '/ecf-sequences',
            '/electronic-invoices',
        ];
    }

    public function test_live_shops_have_no_404_on_core_pages(): void
    {
        if (! getenv('LIVE_SMOKE') && ! env('LIVE_SMOKE')) {
            $this->markTestSkipped('Set LIVE_SMOKE=1 to crawl live shops.');
        }

        $this->withoutVite();
        $failures = [];

        $cases = [
            ['repuesto@comerciosrd.com', 'gg', 'owner'],
            ['vprespuestos@comerciosrd.local', 'gg', 'cashier'],
            ['farmacias@n.com', 'aa', 'owner'],
            ['jonfarmacias@n.com', 'aa', 'seller'],
            ['crown@comerciosrd.com', 'crown', 'owner'],
        ];

        foreach ($cases as [$email, $slug, $role]) {
            $user = User::query()->where('email', $email)->first();
            $business = Business::query()->where('slug', $slug)->first();
            if (! $user || ! $business) {
                $failures[] = "MISSING user/shop {$email} {$slug}";
                continue;
            }

            $this->actingAs($user);
            try {
                filament()->setCurrentPanel(filament()->getPanel('admin'));
                filament()->setTenant($business);
            } catch (\Throwable) {
            }
            app(AuthContextResolver::class)->apply($user, $business);

            $urls = ['/caja/'.$slug, '/caja/'.$slug.'/api/bootstrap'];
            foreach ($this->tenantPages() as $suffix) {
                $urls[] = '/admin/'.$slug.$suffix;
            }

            if ($role === 'cashier') {
                $urls = [
                    '/caja/'.$slug,
                    '/caja/'.$slug.'/api/bootstrap',
                    '/caja/'.$slug.'/api/quotes',
                    '/admin/'.$slug.'/pos',
                    '/admin/'.$slug.'/quotes',
                    '/admin/'.$slug.'/quotes/create',
                    '/admin/'.$slug.'/sales',
                    '/admin/'.$slug.'/customers',
                    '/admin/'.$slug.'/cash-sessions',
                    '/admin/'.$slug.'/preventa',
                ];
            } elseif ($role === 'seller') {
                $urls = [
                    '/admin/'.$slug.'/preventa',
                    '/admin/'.$slug.'/quotes',
                    '/admin/'.$slug.'/quotes/create',
                    '/admin/'.$slug.'/orders',
                    '/admin/'.$slug.'/customers',
                    '/admin/'.$slug.'/products',
                    '/caja/'.$slug,
                ];
            }

            foreach ($urls as $url) {
                try {
                    $response = $this->get($url);
                    $status = $response->getStatusCode();
                } catch (\Throwable $e) {
                    $failures[] = "{$role} {$email} {$url} -> EX ".$e->getMessage();
                    continue;
                }
                if (in_array($status, [404, 500], true)) {
                    $snippet = trim(preg_replace('/\s+/', ' ', substr(strip_tags($response->getContent()), 0, 180)));
                    $failures[] = "{$role} {$email} {$url} -> {$status} {$snippet}";
                }
            }
        }

        $master = User::query()->where('email', 'admin@comerciosrd.local')->first();
        if ($master) {
            $this->actingAs($master);
            try {
                filament()->setCurrentPanel(filament()->getPanel('master'));
            } catch (\Throwable) {
            }
            foreach ([
                '/master',
                '/master/businesses',
                '/master/businesses/create',
                '/master/users',
                '/master/users/create',
                '/master/precios-planes',
                '/master/subscription-invoices',
                '/master/master-audit-logs',
                '/admin/login',
                '/master/login',
                '/',
            ] as $url) {
                $response = $this->get($url);
                $status = $response->getStatusCode();
                if (in_array($status, [404, 500], true)) {
                    $failures[] = "master {$url} -> {$status}";
                }
            }
        }

        $this->assertSame([], $failures, "404/500:\n".implode("\n", $failures));
    }

    public function test_dump_one_500(): void
    {
        if (! getenv('LIVE_SMOKE_DUMP')) {
            $this->markTestSkipped('dump');
        }
        $this->withoutVite();
        $this->withoutExceptionHandling();
        $email = getenv('DUMP_EMAIL') ?: 'vprespuestos@comerciosrd.local';
        $url = getenv('DUMP_URL') ?: '/admin/gg/quotes';
        $user = User::query()->where('email', $email)->first();
        $this->actingAs($user);
        $parts = explode('/', trim($url, '/'));
        if (($parts[0] ?? '') === 'admin' && isset($parts[1])) {
            $b = Business::query()->where('slug', $parts[1])->first();
            if ($b) {
                app(AuthContextResolver::class)->apply($user, $b);
            }
        }
        $r = $this->get($url);
        fwrite(STDERR, "STATUS=".$r->status()." LOC=".(string) $r->headers->get('Location')." LEN=".strlen($r->getContent())."\n");
        if ($r->status() >= 400) {
            fwrite(STDERR, substr($r->getContent(), 0, 1500)."\n");
        }
        $this->assertTrue($r->status() < 500, 'status '.$r->status());
    }
}
