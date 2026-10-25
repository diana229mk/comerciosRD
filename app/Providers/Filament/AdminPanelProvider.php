<?php

namespace App\Providers\Filament;

use App\Http\Middleware\CheckBranchActivation;
use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Business;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(\App\Filament\Auth\Login::class)
            ->brandName(fn () => filament()->getTenant()?->name ?? 'ComerciosRD')
            ->homeUrl(function (): ?string {
                try {
                    $destination = session(\App\Services\AuthContextResolver::SESSION_PANEL_DESTINATION);
                    if (is_string($destination) && $destination !== '') {
                        return $destination;
                    }

                    $user = auth()->user();
                    $tenant = filament()->getTenant();
                    if ($user instanceof \App\Models\User && $tenant instanceof Business) {
                        return app(\App\Services\AuthContextResolver::class)
                            ->panelDestination($user, $tenant);
                    }
                } catch (\Throwable) {
                    // Sin tenant / sesión aún: usar home por defecto
                }

                return null;
            })
            ->colors([
                'primary' => Color::Amber,
                'danger' => Color::Rose,
                'success' => Color::Emerald,
                'warning' => Color::Orange,
                'info' => Color::Sky,
                'gray' => Color::Slate,
            ])
            ->font('Inter')
            ->sidebarCollapsibleOnDesktop()
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => Blade::render("@vite('resources/css/filament/admin/theme.css')")
                    .'<script src="'.e(asset('js/livewire-419-reload.js')).'"></script>'
            )
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): string => Blade::render('@livewire(\App\Livewire\BranchSwitcher::class)')
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => '<script src="'.e(asset('js/barcode-scanner.js')).'" defer></script>'
            )
            // ==================== MULTI-TENANCY ====================
            ->tenant(
                Business::class,
                slugAttribute: 'slug',
                ownershipRelationship: 'business',
            )
            ->tenantMiddleware([
                CheckBranchActivation::class,
                EnsureTenantContext::class,
                \App\Http\Middleware\RedirectToRoleHome::class,
            ], isPersistent: true)
            // =======================================================
            // Grupos con íconos y colapsables. Orden = flujo natural del día
            // a día del dueño (vender → comprar → inventario → giros → finanzas
            // → facturación → contabilidad → reportes → config → seguridad).
            ->navigationGroups([
                NavigationGroup::make('Ventas')
                    ->icon('heroicon-o-shopping-cart')
                    ->collapsible(),
                NavigationGroup::make('Compras')
                    ->icon('heroicon-o-truck')
                    ->collapsible(),
                NavigationGroup::make('Inventario')
                    ->icon('heroicon-o-archive-box')
                    ->collapsible(),
                NavigationGroup::make('Farmacia')
                    ->icon('heroicon-o-beaker')
                    ->collapsible(),
                NavigationGroup::make('Ferretería')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->collapsible(),
                NavigationGroup::make('Cocina')
                    ->icon('heroicon-o-fire')
                    ->collapsible(),
                NavigationGroup::make('Tienda')
                    ->icon('heroicon-o-swatch')
                    ->collapsible(),
                NavigationGroup::make('Finanzas')
                    ->icon('heroicon-o-banknotes')
                    ->collapsible(),
                NavigationGroup::make('Facturación Electrónica')
                    ->icon('heroicon-o-document-text')
                    ->collapsible(),
                NavigationGroup::make('Contabilidad')
                    ->icon('heroicon-o-calculator')
                    ->collapsible(),
                NavigationGroup::make('Reportes')
                    ->icon('heroicon-o-chart-bar')
                    ->collapsible(),
                NavigationGroup::make('Configuración')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->collapsible(),
                NavigationGroup::make('Seguridad')
                    ->icon('heroicon-o-shield-check')
                    ->collapsible(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                \App\Filament\Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // Seguridad HTTP (también en bootstrap web; Filament usa su propio stack)
                SecurityHeaders::class,
                ContentSecurityPolicy::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                'throttle:filament-panel',
                \App\Http\Middleware\RefreshAuthenticatedContext::class,
                EnsureTwoFactorVerified::class,
                \App\Http\Middleware\RedirectBareAdminToTenant::class,
            ]);
    }
}
