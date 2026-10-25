<?php

namespace App\Filament\Master\Resources;

use App\Enums\BusinessType;
use App\Enums\SubscriptionPlan;
use App\Filament\Master\Resources\BusinessResource\Pages;
use App\Models\Business;
use App\Models\MasterAuditLog;
use App\Services\SeatLimitService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BusinessResource extends Resource
{
    protected static ?string $model = Business::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Negocios';

    protected static ?string $modelLabel = 'Negocio';

    protected static ?string $pluralModelLabel = 'Negocios';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Forms\Set $set, Forms\Get $get): void {
                        if (filled($get('slug'))) {
                            return;
                        }
                        $set('slug', \Illuminate\Support\Str::slug((string) $state));
                    }),

                Forms\Components\TextInput::make('slug')
                    ->label('Slug (URL)')
                    ->required()
                    ->maxLength(64)
                    ->alphaDash()
                    ->unique(table: 'businesses', column: 'slug', ignoreRecord: true)
                    ->dehydrateStateUsing(fn (?string $state): string => \Illuminate\Support\Str::slug((string) $state))
                    ->helperText('Identificador único en la URL. Ej: milan → /caja/milan. No puede ser uno que ya exista (aa, gg, crown).')
                    ->validationMessages([
                        'unique' => 'Ese slug ya lo usa otro negocio. Elige otro (ej. milan o ferreteria-milan).',
                        'alpha_dash' => 'Usa solo letras, números y guiones.',
                    ]),

                Forms\Components\Select::make('type')
                    ->label('Tipo de Negocio')
                    ->options(Business::TYPES)
                    ->default('general')
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (?string $state, Forms\Set $set): void {
                        // Sugiere módulos del giro; el Master puede agregar/quitar según el plan
                        $set('enabled_modules', Business::defaultModulesFor($state ?? 'general'));
                    })
                    ->helperText('Define el giro del negocio. Se sugieren módulos prioritarios; puedes agregar o quitar según el plan contratado.'),

                Forms\Components\Select::make('owner_id')
                    ->label('Propietario existente')
                    ->relationship('owner', 'name')
                    ->searchable()
                    ->preload(false)
                    ->helperText('Opcional al crear: si defines un dueño nuevo abajo, se ignora este campo.')
                    ->visibleOn('edit'),

                Forms\Components\TextInput::make('phone')
                    ->label('Teléfono')
                    ->tel()
                    ->maxLength(30),

                Forms\Components\TextInput::make('email')
                    ->label('Correo del negocio')
                    ->email()
                    ->maxLength(255),

                Forms\Components\TextInput::make('address')
                    ->label('Dirección')
                    ->maxLength(255),

                Forms\Components\TextInput::make('city')
                    ->label('Ciudad')
                    ->maxLength(100),

                Forms\Components\TextInput::make('province')
                    ->label('Provincia')
                    ->maxLength(100),

                Forms\Components\TextInput::make('rnc')
                    ->label('RNC')
                    ->maxLength(20),

                Forms\Components\Toggle::make('is_active')
                    ->label('Activo')
                    ->default(true),

                Forms\Components\Section::make('Plan de usuarios (DOP / mes)')
                    ->description('El dueño/admin ocupa 1 asiento. Los extras son cajeros y el resto del equipo. Para locales grandes suma asientos extra.')
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        Forms\Components\Select::make('subscription_plan')
                            ->label('Plan')
                            ->options(SubscriptionPlan::options())
                            ->default(config('billing.default_plan', 'esencial'))
                            ->required()
                            ->native(false)
                            ->live(),

                        Forms\Components\TextInput::make('extra_seats')
                            ->label('Asientos extra (ajuste)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(200)
                            ->default(0)
                            ->live()
                            ->helperText(fn () => 'Fuera del plan. RD$ '.number_format(app(\App\Services\BillingCatalog::class)->extraSeatPrice(), 0).' cada uno / mes.'),

                        Forms\Components\Placeholder::make('plan_total')
                            ->label('Total mensual')
                            ->content(function (Forms\Get $get): string {
                                $plan = SubscriptionPlan::tryFromMixed($get('subscription_plan'));
                                $extra = max(0, (int) $get('extra_seats'));
                                $unit = app(\App\Services\BillingCatalog::class)->extraSeatPrice();
                                $list = $plan->price() + ($extra * $unit);
                                $promo = max(0, min(100, (int) ($get('promo_percent') ?? 0)));
                                $until = $get('promo_until');
                                $active = $promo > 0 && ($until === null || $until === '' || \Illuminate\Support\Carbon::parse($until)->startOfDay()->gte(now()->startOfDay()));
                                $total = $active ? (int) round($list * (100 - $promo) / 100) : $list;
                                $line = sprintf(
                                    'RD$ %s/mes · %d usuarios incluidos + %d extra = %d asientos',
                                    number_format($total, 0),
                                    $plan->includedSeats(),
                                    $extra,
                                    $plan->includedSeats() + $extra
                                );
                                if ($active && $promo > 0) {
                                    $line .= sprintf(' · oferta %d%% (lista RD$ %s)', $promo, number_format($list, 0));
                                }

                                return $line;
                            }),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Promoción / oferta (este local)')
                    ->description('Solo este negocio. El resto sigue pagando el precio de lista. Vacío o 0% = sin oferta. Sin fecha = se queda con la oferta hasta que la quites.')
                    ->icon('heroicon-o-gift')
                    ->schema([
                        Forms\Components\TextInput::make('promo_percent')
                            ->label('Descuento %')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(0)
                            ->suffix('%')
                            ->live(),
                        Forms\Components\DatePicker::make('promo_until')
                            ->label('Vigente hasta')
                            ->native(false)
                            ->helperText('Déjalo vacío si quieres que se queden con la oferta sin fecha de corte.'),
                        Forms\Components\TextInput::make('promo_note')
                            ->label('Nota interna')
                            ->maxLength(120)
                            ->placeholder('Ej. Lanzamiento ferretería, 3 meses'),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Usuario Dueño del Negocio')
                    ->description('Se creará automáticamente el usuario que administrará este negocio e iniciará sesión en /admin.')
                    ->icon('heroicon-o-user-plus')
                    ->schema([
                        Forms\Components\TextInput::make('owner_name')
                            ->label('Nombre del Dueño')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('owner_email')
                            ->label('Correo del Dueño')
                            ->email()
                            ->required()
                            ->unique(table: 'users', column: 'email')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('owner_password')
                            ->label('Contraseña Temporal')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->rule(\Illuminate\Validation\Rules\Password::defaults())
                            ->helperText('El dueño debería cambiarla desde Configuración → Cambiar Contraseña tras el primer acceso.'),
                    ])
                    ->columns(2)
                    ->visibleOn('create'),

                Forms\Components\Section::make('Módulos del Negocio (plan)')
                    ->description('Los módulos se sugieren según el tipo de negocio, pero puedes agregar o quitar cualquiera según el plan del cliente. Vacío = acceso a todos (compatibilidad).')
                    ->icon('heroicon-o-squares-2x2')
                    ->schema([
                        Forms\Components\CheckboxList::make('enabled_modules')
                            ->label('Módulos habilitados')
                            ->options(Business::availableModules())
                            ->columns(2)
                            ->gridDirection('row')
                            ->bulkToggleable()
                            ->searchable()
                            ->helperText('Plan flexible: marca lo incluido. Ejemplo: un colmado puede sumar Contabilidad o e-CF si su plan lo permite.')
                            ->dehydrated(),

                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('sugerirPorTipo')
                                ->label('Restablecer módulos del tipo')
                                ->icon('heroicon-o-sparkles')
                                ->color('gray')
                                ->requiresConfirmation()
                                ->modalHeading('¿Restablecer módulos por tipo?')
                                ->modalDescription('Se reemplazará la lista actual por los módulos prioritarios del tipo de negocio seleccionado. Luego podrás volver a ajustar el plan.')
                                ->action(function (Forms\Get $get, Forms\Set $set): void {
                                    $set('enabled_modules', Business::defaultModulesFor((string) ($get('type') ?? 'general')));
                                }),
                        ]),
                    ])
                    ->collapsible()
                    ->collapsed(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Negocio')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof BusinessType
                        ? $state->label()
                        : (BusinessType::tryFrom((string) $state)?->label() ?? (string) $state)),

                Tables\Columns\TextColumn::make('owner.name')
                    ->label('Propietario')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Teléfono')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('rnc')
                    ->label('RNC')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('subscription_plan')
                    ->label('Plan')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof SubscriptionPlan
                        ? $state->label()
                        : SubscriptionPlan::tryFromMixed($state)->label()),

                Tables\Columns\TextColumn::make('seats')
                    ->label('Usuarios')
                    ->getStateUsing(function (Business $record): string {
                        $snap = app(SeatLimitService::class)->snapshot($record);

                        return $snap['used'].'/'.$snap['max'];
                    }),

                Tables\Columns\TextColumn::make('monthly')
                    ->label('DOP/mes')
                    ->getStateUsing(function (Business $record): string {
                        $seats = app(SeatLimitService::class);
                        $pay = $seats->monthlyTotal($record);
                        $list = $seats->listPrice($record);
                        if ($record->hasActivePromo()) {
                            return 'RD$ '.number_format($pay, 0).' (−'.$record->promoPercent().'%)';
                        }

                        return 'RD$ '.number_format($list, 0);
                    }),

                Tables\Columns\TextColumn::make('promo_percent')
                    ->label('Oferta')
                    ->formatStateUsing(function ($state, Business $record): string {
                        if (! $record->hasActivePromo()) {
                            return '—';
                        }
                        $until = $record->promo_until?->format('d/m/Y');

                        return $record->promoPercent().'%'.($until ? ' hasta '.$until : ' sin corte');
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Registrado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->deferLoading()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Activos'),

                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(Business::TYPES),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('suspender')
                    ->label('Suspender')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Suspender negocio')
                    ->modalDescription('El negocio no podrá usar el panel hasta que se reactive.')
                    ->action(function (Business $record) {
                        $record->update(['is_active' => false]);

                        MasterAuditLog::log(
                            'suspend_business',
                            $record,
                            ['is_active' => true],
                            ['is_active' => false]
                        );

                        Notification::make()
                            ->title('Negocio suspendido')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (Business $record) => $record->is_active),

                Tables\Actions\Action::make('activar')
                    ->label('Activar')
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Business $record) {
                        $record->update(['is_active' => true]);

                        MasterAuditLog::log(
                            'activate_business',
                            $record,
                            ['is_active' => false],
                            ['is_active' => true]
                        );

                        Notification::make()
                            ->title('Negocio activado')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (Business $record) => ! $record->is_active),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Master\Resources\BusinessResource\RelationManagers\BranchesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBusinesses::route('/'),
            'create' => Pages\CreateBusiness::route('/create'),
            'edit' => Pages\EditBusiness::route('/{record}/edit'),
        ];
    }
}
