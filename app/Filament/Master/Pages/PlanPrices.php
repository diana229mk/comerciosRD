<?php

namespace App\Filament\Master\Pages;

use App\Models\MasterAuditLog;
use App\Services\BillingCatalog;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class PlanPrices extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'Precios de planes';

    protected static ?string $title = 'Precios de planes (DOP / mes)';

    protected static ?string $navigationGroup = 'Suscripciones';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.master.pages.plan-prices';

    protected static ?string $slug = 'precios-planes';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(app(BillingCatalog::class)->all());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Planes')
                    ->description('Estos montos se usan en altas nuevas y en facturas abiertas del mes. Las ya cobradas no cambian.')
                    ->schema([
                        Forms\Components\TextInput::make('esencial')
                            ->label('Esencial (2 asientos)')
                            ->numeric()
                            ->prefix('RD$')
                            ->required()
                            ->minValue(0),
                        Forms\Components\TextInput::make('equipo')
                            ->label('Equipo (3 asientos)')
                            ->numeric()
                            ->prefix('RD$')
                            ->required()
                            ->minValue(0),
                        Forms\Components\TextInput::make('completo')
                            ->label('Completo (4 asientos)')
                            ->numeric()
                            ->prefix('RD$')
                            ->required()
                            ->minValue(0),
                        Forms\Components\TextInput::make('extra_seat')
                            ->label('Asiento extra')
                            ->helperText('Se suma por cada asiento fuera del plan.')
                            ->numeric()
                            ->prefix('RD$')
                            ->required()
                            ->minValue(0),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $catalog = app(BillingCatalog::class);
        $old = $catalog->all();
        $data = $this->form->getState();
        $catalog->save($data);

        MasterAuditLog::log('billing.prices.updated', null, $old, $catalog->all());

        Notification::make()
            ->title('Precios actualizados')
            ->body('Los nuevos montos aplican desde ahora. Recarga el alta de negocio para verlos.')
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return (bool) (auth()->user()?->is_master_admin ?? false);
    }
}
