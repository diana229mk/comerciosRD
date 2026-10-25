<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class PharmacySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Configuración Farmacia';

    protected static ?string $title = 'Configuración Farmacia';

    protected static ?string $navigationGroup = 'Farmacia';

    protected static ?int $navigationSort = 99;

    protected static string $view = 'filament.pages.pharmacy-settings';

    public ?array $data = [];

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = Filament::getTenant();

        $user = auth()->user();
        $role = $user?->getRoleInCurrentBusiness();

        return $tenant && method_exists($tenant, 'isPharmacy') && $tenant->isPharmacy()
            && in_array($role, ['owner', 'admin'], true);
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public function mount(): void
    {
        $tenant = Filament::getTenant();
        $settings = $tenant?->settings ?? [];
        $this->form->fill([
            'prescription_mode' => $settings['pharmacy']['prescription_mode'] ?? 'strict',
            'near_expiry_days' => $settings['pharmacy']['near_expiry_days'] ?? 30,
            'blocks_near_expiry_sales' => (bool) ($settings['pharmacy']['blocks_near_expiry_sales'] ?? false),
            ...(\App\Support\EcfFeature::enabled() ? [
                'auto_credit_note_on_return' => (bool) ($settings['ecf']['auto_credit_note_on_return'] ?? false),
            ] : []),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Recetas médicas')
                ->description('Cómo se registran las recetas en el POS.')
                ->schema([
                    Forms\Components\Radio::make('prescription_mode')
                        ->label('Modo de receta')
                        ->options([
                            'basic' => 'Básico — solo paciente y médico (rápido, sin renglones)',
                            'strict' => 'Estricto — receta completa con renglones y dispensación por línea',
                        ])
                        ->helperText('Los medicamentos controlados (Anexo I/II/psicotrópicos) SIEMPRE se validan en modo estricto por seguridad.')
                        ->default('strict')
                        ->required(),
                ]),

            Forms\Components\Section::make('Vencimientos')
                ->schema([
                    Forms\Components\TextInput::make('near_expiry_days')
                        ->label('Días para alerta de próximo vencimiento')
                        ->numeric()->minValue(0)->maxValue(365)->default(30)->required(),
                    Forms\Components\Toggle::make('blocks_near_expiry_sales')
                        ->label('Bloquear venta de lotes próximos a vencer')
                        ->helperText('Los lotes vencidos siempre se bloquean.'),
                ]),

            Forms\Components\Section::make('e-CF fiscal')
                ->visible(fn (): bool => \App\Support\EcfFeature::enabled())
                ->schema([
                    Forms\Components\Toggle::make('auto_credit_note_on_return')
                        ->label('Emitir automáticamente Nota de Crédito e-CF (34) en devoluciones')
                        ->helperText('Se aplica cuando la venta original tiene e-CF tipo 31 o 32.'),
                ]),
        ])->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Guardar cambios')
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return;
        }
        $data = $this->form->getState();
        $settings = $tenant->settings ?? [];
        $settings['pharmacy']['prescription_mode'] = $data['prescription_mode'] ?? 'strict';
        $settings['pharmacy']['near_expiry_days'] = (int) ($data['near_expiry_days'] ?? 30);
        $settings['pharmacy']['blocks_near_expiry_sales'] = (bool) ($data['blocks_near_expiry_sales'] ?? false);
        if (\App\Support\EcfFeature::enabled()) {
            $settings['ecf']['auto_credit_note_on_return'] = (bool) ($data['auto_credit_note_on_return'] ?? false);
        }
        $tenant->forceFill(['settings' => $settings])->save();

        Notification::make()->title('Configuración actualizada')->success()->send();
    }
}
