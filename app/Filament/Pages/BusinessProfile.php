<?php

namespace App\Filament\Pages;

use App\Models\Business;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Ficha del negocio actual (RNC, contacto) — necesaria para e-CF.
 */
class BusinessProfile extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Datos del Negocio';

    protected static ?string $title = 'Datos del Negocio';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.business-profile';

    protected static ?string $slug = 'datos-del-negocio';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $business = Filament::getTenant();
        if (! $business instanceof Business) {
            return;
        }

        $seats = app(\App\Services\SeatLimitService::class)->snapshot($business);

        $this->form->fill([
            'name' => $business->name,
            'rnc' => $business->rnc,
            'phone' => $business->phone,
            'email' => $business->email,
            'address' => $business->address,
            'city' => $business->city,
            'province' => $business->province,
            'plan_label' => $seats['plan']->label().' — '.$seats['plan']->summary(),
            'plan_seats' => $seats['used'].' de '.$seats['max'].' usuarios',
            'plan_price' => 'RD$ '.number_format($seats['monthly'], 0).'/mes',
            'tax_rate' => $business->taxRate(),
            'prices_include_tax' => $business->pricesIncludeTax(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Plan contratado')
                    ->description('Lo asigna ComerciosRD. Para más usuarios hay que subir de plan o pedir asientos extra.')
                    ->schema([
                        Forms\Components\Placeholder::make('plan_label')
                            ->label('Plan')
                            ->content(fn (): string => (string) ($this->data['plan_label'] ?? '—')),
                        Forms\Components\Placeholder::make('plan_seats')
                            ->label('Usuarios')
                            ->content(fn (): string => (string) ($this->data['plan_seats'] ?? '—')),
                        Forms\Components\Placeholder::make('plan_price')
                            ->label('Cuota')
                            ->content(fn (): string => (string) ($this->data['plan_price'] ?? '—')),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Identificación fiscal')
                    ->description('El RNC es obligatorio para emitir comprobantes electrónicos (e-CF).')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre comercial')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('rnc')
                            ->label('RNC')
                            ->required()
                            ->maxLength(20)
                            ->helperText('Registro Nacional de Contribuyentes (DGII).'),

                        Forms\Components\TextInput::make('phone')
                            ->label('Teléfono')
                            ->tel()
                            ->maxLength(30),

                        Forms\Components\TextInput::make('email')
                            ->label('Correo')
                            ->email()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('address')
                            ->label('Dirección')
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('city')
                            ->label('Ciudad')
                            ->maxLength(100),

                        Forms\Components\TextInput::make('province')
                            ->label('Provincia')
                            ->maxLength(100),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('ITBIS')
                    ->description('Por defecto la caja SUMA el 18% al precio. Si tus precios de góndola ya lo traen, actívalo para no cobarlo dos veces. Las ventas anteriores no cambian.')
                    ->schema([
                        Forms\Components\TextInput::make('tax_rate')
                            ->label('Tasa ITBIS (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(18),
                        Forms\Components\Toggle::make('prices_include_tax')
                            ->label('Los precios ya incluyen ITBIS')
                            ->helperText('Ejemplo: el producto vale RD$ 118 en el estante y el cliente paga 118, no 139.')
                            ->inline(false),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $business = Filament::getTenant();
        if (! $business instanceof Business) {
            Notification::make()->title('Sin negocio activo')->danger()->send();

            return;
        }

        $data = $this->form->getState();

        $settings = $business->settings ?? [];
        $settings['tax'] = array_merge(is_array($settings['tax'] ?? null) ? $settings['tax'] : [], [
            'rate' => (float) ($data['tax_rate'] ?? 18),
            'prices_include_tax' => (bool) ($data['prices_include_tax'] ?? false),
        ]);

        $business->forceFill([
            'name' => $data['name'],
            'rnc' => $data['rnc'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'province' => $data['province'] ?? null,
            'settings' => $settings,
        ])->save();

        Notification::make()
            ->title('Datos del negocio actualizados')
            ->success()
            ->send();
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasRoleInBusiness(['owner', 'admin']);
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }
}
