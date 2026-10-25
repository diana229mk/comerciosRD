<?php

namespace App\Providers;

use App\Contracts\MysqlServerIdentityReader;
use App\Contracts\ProductSearch;
use App\Contracts\RecordsSale;
use App\Http\Responses\TenantLoginResponse;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Payable;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\Receivable;
use App\Models\Sale;
use App\Observers\BranchTelemetryObserver;
use App\Observers\OrderKitchenObserver;
use App\Observers\SaleObserver;
use App\Policies\CustomerPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\OrderPolicy;
use App\Policies\PayablePolicy;
use App\Policies\ProductCategoryPolicy;
use App\Policies\ProductPolicy;
use App\Policies\ReceivablePolicy;
use App\Policies\SalePolicy;
use App\Rules\StrongPassword;
use App\Services\BranchAccessContext;
use App\Services\PdoMysqlServerIdentityReader;
use App\Services\ProductSearchService;
use App\Services\SaleService;
use App\Support\TestingDatabaseGuard;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MysqlServerIdentityReader::class, PdoMysqlServerIdentityReader::class);

        // FASE H1 · Cache request-scoped del contexto de acceso por sucursal.
        // scoped() garantiza una instancia por request HTTP y por comando CLI.
        $this->app->scoped(BranchAccessContext::class);

        $this->app->singleton(
            LoginResponse::class,
            TenantLoginResponse::class
        );

        // Puertos de escala: POS y cobro hablan con interfaces, no con MySQL.
        $this->app->singleton(ProductSearch::class, ProductSearchService::class);
        $this->app->singleton(RecordsSale::class, SaleService::class);
    }

    public function boot(): void
    {
        // Inventario: movimientos automáticos en ventas
        Sale::observe(SaleObserver::class);
        Order::observe(OrderKitchenObserver::class);

        // FASE H5 · Telemetría de sucursales: alerta si branch_id nace null
        // en un negocio que ya tiene sucursales activas (bypass de TracksBranch).
        Sale::observe(BranchTelemetryObserver::class);
        Order::observe(BranchTelemetryObserver::class);
        Expense::observe(BranchTelemetryObserver::class);
        CashSession::observe(BranchTelemetryObserver::class);
        Purchase::observe(BranchTelemetryObserver::class);

        // ==================== POLICIES (aislamiento + RBAC) ====================
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(ProductCategory::class, ProductCategoryPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Sale::class, SalePolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Expense::class, ExpensePolicy::class);
        Gate::policy(Payable::class, PayablePolicy::class);
        Gate::policy(AccountPayable::class, PayablePolicy::class); // legacy
        Gate::policy(Receivable::class, ReceivablePolicy::class);
        Gate::policy(AccountReceivable::class, ReceivablePolicy::class); // legacy

        $this->registerLoginRateLimiters();
        $this->registerPosRateLimiters();
        RateLimiter::for('menu-public', function (Request $request) {
            return Limit::perMinute(60)->by((string) $request->ip());
        });
        RateLimiter::for('menu-assets', function (Request $request) {
            return Limit::perMinute(20)
                ->by('menu-assets|ip|'.(string) $request->ip())
                ->response(fn () => response('Demasiadas solicitudes. Intenta de nuevo en un minuto.', 429));
        });
        RateLimiter::for('two-factor', function (Request $request) {
            $userId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)
                    ->by('two-factor|user|'.$userId)
                    ->response(fn () => response('Demasiados intentos de verificación. Intenta de nuevo en un minuto.', 429)),
                Limit::perMinute(30)
                    ->by('two-factor|ip|'.$ip)
                    ->response(fn () => response('Demasiadas solicitudes. Intenta de nuevo en un minuto.', 429)),
            ];
        });
        RateLimiter::for('documents', function (Request $request) {
            $userId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');
            $tenant = (string) (
                $request->route('tenant')
                ?? session('current_business_id')
                ?? $request->user()?->current_business_id
                ?? 'none'
            );
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(60)->by('documents|user|'.$userId.'|tenant|'.$tenant),
                Limit::perMinute(300)->by('documents|ip|'.$ip),
            ];
        });
        RateLimiter::for('filament-panel', function (Request $request) {
            $userId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(300)->by('filament-panel|user|'.$userId),
                Limit::perMinute(1500)->by('filament-panel|ip|'.$ip),
            ];
        });
        $this->registerPasswordDefaults();
        $this->assertProductionDebugOff();
        $this->protectTestDatabaseMigrations();
    }

    protected function protectTestDatabaseMigrations(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $command = $event->command;
            $destructiveCommands = [
                'db:wipe',
                'migrate:fresh',
                'migrate:refresh',
                'migrate:reset',
                'migrate:rollback',
            ];
            $migrationCommand = in_array($command, $destructiveCommands, true)
                || ($this->app->environment('testing')
                    && str_starts_with($command, 'migrate')
                    && $command !== 'migrate:status');

            if ($migrationCommand) {
                TestingDatabaseGuard::assertMigrationTargetIsSafe($this->app);
            }
        });
    }

    /**
     * Rate limiters de login (admin + master).
     *
     * - login: panel de negocio (/admin)
     * - master-login: panel master (/master) — más estricto
     */
    protected function registerLoginRateLimiters(): void
    {
        // Panel Admin / negocio — 5/min por email+IP, 15/min por IP
        RateLimiter::for('login', function (Request $request) {
            $cfg = config('security.login_rate_limit', []);
            $ip = (string) $request->ip();
            // Filament Livewire envía data.email; forms clásicos usan email
            $email = strtolower(trim((string) (
                $request->input('data.email')
                ?? $request->input('email')
                ?? ''
            )));

            $perEmailIp = max(1, (int) ($cfg['per_minute_email_ip'] ?? 5));
            $perIp = max(1, (int) ($cfg['per_minute_ip'] ?? 15));

            $limits = [
                Limit::perMinute($perIp)
                    ->by('login|ip|'.$ip)
                    ->response(function () {
                        return response(
                            'Demasiados intentos. Intenta de nuevo en 1 minuto.',
                            429
                        );
                    }),
            ];

            if ($email !== '') {
                $limits[] = Limit::perMinute($perEmailIp)
                    ->by('login|email-ip|'.sha1($email.'|'.$ip))
                    ->response(function () {
                        return response(
                            'Demasiados intentos. Intenta de nuevo en 1 minuto.',
                            429
                        );
                    });
            }

            return $limits;
        });

        // Panel Master (IP + email, ventanas largas)
        RateLimiter::for('master-login', function (Request $request) {
            $cfg = config('security.master_login_throttle', []);
            $ip = (string) $request->ip();

            $email = strtolower(trim((string) (
                $request->input('data.email')
                ?? $request->input('email')
                ?? ''
            )));

            $perMinute = max(1, (int) ($cfg['per_minute'] ?? 5));
            $perEmailMinute = max(1, (int) ($cfg['per_email_minute'] ?? 5));
            $perEmailHour = max(1, (int) ($cfg['per_email_hour'] ?? 15));
            $perHour = max(1, (int) ($cfg['per_hour'] ?? 15));
            $perDay = max(1, (int) ($cfg['per_day'] ?? 30));

            $limits = [
                Limit::perMinute($perMinute)->by('master-login|ip|min|'.$ip),
                Limit::perHour($perHour)->by('master-login|ip|hour|'.$ip),
                Limit::perDay($perDay)->by('master-login|ip|day|'.$ip),
            ];

            if ($email !== '') {
                $emailKey = sha1($email);
                $limits[] = Limit::perMinute($perEmailMinute)->by('master-login|email|min|'.$emailKey);
                $limits[] = Limit::perHour($perEmailHour)->by('master-login|email|hour|'.$emailKey);
            }

            return $limits;
        });
    }

    protected function registerPosRateLimiters(): void
    {
        RateLimiter::for('pos-search', function (Request $request) {
            $max = max(30, (int) config('scale.pos.search_per_minute', 300));
            $key = 'pos-search|'.($request->user()?->getKey() ?? $request->ip()).'|'.$request->route('tenant');

            return Limit::perMinute($max)->by($key);
        });

        RateLimiter::for('pos-checkout', function (Request $request) {
            $max = max(5, (int) config('scale.pos.checkout_per_minute', 40));
            $key = 'pos-checkout|'.($request->user()?->getKey() ?? $request->ip()).'|'.$request->route('tenant');

            return Limit::perMinute($max)->by($key);
        });

        RateLimiter::for('pos-cash', function (Request $request) {
            $key = 'pos-cash|'.($request->user()?->getKey() ?? $request->ip()).'|'.$request->route('tenant');

            return Limit::perMinute(20)->by($key);
        });
    }

    protected function registerPasswordDefaults(): void
    {
        Password::defaults(function () {
            $rule = Password::min((int) config('security.password.min_length', 10))
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols();

            // En producción: rechazar contraseñas filtradas en breaches (HaveIBeenPwned)
            if (app()->environment('production') || config('security.password.uncompromised', false)) {
                $rule = $rule->uncompromised();
            }

            return $rule;
        });

        Validator::extend('strong_password', function ($attribute, $value) {
            $failed = false;
            (new StrongPassword)->validate($attribute, $value, function () use (&$failed) {
                $failed = true;
            });

            return ! $failed;
        }, 'La contraseña no cumple la política de seguridad.');
    }

    /**
     * En producción, APP_DEBUG=true es un riesgo grave de filtración.
     * No lo apagamos en caliente (podría romper deploys), pero lo registramos.
     */
    protected function assertProductionDebugOff(): void
    {
        if (app()->environment('production') && config('app.debug')) {
            logger()->critical('SECURITY: APP_DEBUG=true en producción. Debe ser false.');
        }
    }
}
