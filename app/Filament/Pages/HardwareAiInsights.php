<?php

namespace App\Filament\Pages;

use App\Models\Business;
use App\Models\User;
use App\Services\CrossSellService;
use App\Services\DemandForecastService;
use App\Services\LiquidationRecommendationService;
use Filament\Pages\Page;

class HardwareAiInsights extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';
    protected static ?string $navigationLabel = 'IA · Insights';
    protected static ?string $title = 'IA — Insights inteligentes';
    protected static ?string $navigationGroup = 'Ferretería';
    protected static ?int $navigationSort = 5;
    protected static string $view = 'filament.pages.hardware-ai-insights';
    protected static ?string $slug = 'ia-insights';

    public int $forecastDays = 30;
    public int $crossDays = 90;
    public int $deadDays = 90;

    public function getForecast()
    {
        $tenant = filament()->getTenant();
        if (! $tenant instanceof Business) return collect();
        return app(DemandForecastService::class)->analyze($tenant, (int) $this->forecastDays)->take(30);
    }

    public function getCrossSell()
    {
        $tenant = filament()->getTenant();
        if (! $tenant instanceof Business) return collect();
        return app(CrossSellService::class)->topPairs($tenant, (int) $this->crossDays, 20, 2);
    }

    public function getLiquidation()
    {
        $tenant = filament()->getTenant();
        if (! $tenant instanceof Business) return collect();
        return app(LiquidationRecommendationService::class)->analyze($tenant, (int) $this->deadDays);
    }

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = filament()->getTenant();
        $user = auth()->user();
        if (! $tenant instanceof Business || ! $user instanceof User) return false;
        if (! $tenant->isHardwareStore()) return false;
        return $user->hasRoleInBusiness(['owner', 'admin', 'supervisor']);
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }
}
