<?php

namespace App\Providers\Filament;

use App\Http\Middleware\ConfigureMasterSession;
use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Http\Middleware\MasterIpWhitelist;
use App\Http\Middleware\RestrictMasterPanelIp;
use App\Http\Middleware\SecurityHeaders;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class MasterPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('master')
            ->path('master')
            // Login custom con rate limiting estricto (no soporta login(['rateLimiter' => ...]))
            ->login(\App\Filament\Master\Auth\Login::class)
            ->colors([
                'primary' => Color::Red,
            ])
            ->renderHook(
                \Filament\View\PanelsRenderHook::HEAD_END,
                fn (): string => '<script src="'.e(asset('js/livewire-419-reload.js')).'"></script>'
            )
            ->discoverResources(in: app_path('Filament/Master/Resources'), for: 'App\\Filament\\Master\\Resources')
            ->discoverPages(in: app_path('Filament/Master/Pages'), for: 'App\\Filament\\Master\\Pages')
            ->pages([
                \App\Filament\Master\Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Master/Widgets'), for: 'App\\Filament\\Master\\Widgets')
            ->widgets([
                \App\Filament\Master\Widgets\MasterStatsOverview::class,
                \App\Filament\Master\Widgets\SubscriptionsByPlan::class,
                \App\Filament\Master\Widgets\SubscriptionManagerTable::class,
            ])
            ->middleware([
                // 1) Sesión corta (antes de StartSession)
                ConfigureMasterSession::class,
                // 2) IP whitelist a nivel de ruta (incluye /master/login)
                RestrictMasterPanelIp::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SecurityHeaders::class,
                ContentSecurityPolicy::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                'throttle:filament-panel',
                MasterIpWhitelist::class, // Whitelist IP post-auth (403 + alerta)
                EnsureTwoFactorIsEnabled::class, // Sin 2FA → /master/setup-2fa
                EnsureTwoFactorVerified::class, // Con 2FA → código cada sesión
            ])
            ->brandName('ComerciosRD - Panel Master');
    }
}
