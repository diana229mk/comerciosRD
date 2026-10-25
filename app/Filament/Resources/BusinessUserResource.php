<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\BusinessUserResource\Pages;
use App\Http\Middleware\RefreshAuthenticatedContext;
use App\Models\User;
use App\Support\BusinessRole;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class BusinessUserResource extends Resource
{
    use ChecksBusinessModule;

    protected static string $businessModule = 'employees';

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Equipo';

    protected static ?string $modelLabel = 'Usuario';

    protected static ?string $pluralModelLabel = 'Equipo';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'usuarios';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * User no tiene business_id; el aislamiento es por pivot user_business.
     */
    protected static bool $isScopedToTenant = false;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información del Usuario')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(255)
                            ->validationMessages([
                                'required' => 'El nombre es obligatorio.',
                            ]),

                        Forms\Components\TextInput::make('email')
                            ->label('Correo (usuario)')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('El correo es único en todo el sistema, no solo en este negocio.')
                            ->validationMessages([
                                'required' => 'El correo es obligatorio.',
                                'email' => 'Escribe un correo válido.',
                                'unique' => 'Ese correo ya está en uso. En este sistema no se puede repetir en otro comercio.',
                            ]),

                        Forms\Components\TextInput::make('password')
                            ->label('Clave temporal')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            // Solo se guarda si hay valor; el cast 'hashed' del User cifra al guardar
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->rule(Password::defaults())
                            ->same('password_confirmation')
                            ->helperText(fn (string $operation): string => $operation === 'edit'
                                ? 'Déjalo vacío para no cambiar la clave.'
                                : 'Entrégasela al empleado para el primer acceso.')
                            ->validationMessages([
                                'required' => 'La clave temporal es obligatoria.',
                                'same' => 'La confirmación no coincide con la clave.',
                            ]),

                        Forms\Components\TextInput::make('password_confirmation')
                            ->label('Confirmar clave')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(false),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Rol en el Negocio')
                    ->schema([
                        Forms\Components\Select::make('role')
                            ->label('Rol')
                            ->options(function (): array {
                                $options = BusinessRole::options();
                                // No-elevación: solo un dueño ve la opción "dueño".
                                if (! (auth()->user()?->hasRoleInBusiness(['owner']) ?? false)) {
                                    unset($options['owner']);
                                }

                                return $options;
                            })
                            ->required()
                            ->native(false)
                            ->helperText('Puesto en este comercio. Solo un dueño puede asignar otro dueño.')
                            ->validationMessages([
                                'required' => 'Elige el puesto.',
                            ]),

                        Forms\Components\Select::make('branch_id')
                            ->label('Sucursal')
                            ->options(function (): array {
                                $tenant = Filament::getTenant();
                                if (! $tenant) {
                                    return [];
                                }

                                return \App\Models\Branch::query()
                                    ->where('business_id', $tenant->getKey())
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->searchable()
                            ->nullable()
                            ->placeholder('Sin sucursal específica')
                            ->helperText('Opcional. Si el negocio no tiene sucursales, déjalo vacío.')
                            ->visible(function (): bool {
                                $tenant = Filament::getTenant();

                                return $tenant
                                    ? \App\Models\Branch::query()
                                        ->where('business_id', $tenant->getKey())
                                        ->where('is_active', true)
                                        ->exists()
                                    : false;
                            }),

                        Forms\Components\Toggle::make('pivot_is_active')
                            ->label('Activo')
                            ->default(true)
                            ->helperText('Si lo apagas, no entra a este comercio. El dueño y los demás siguen dentro.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('email')
                    ->label('Correo')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('role')
                    ->label('Puesto')
                    ->badge()
                    ->getStateUsing(function (User $record): ?string {
                        $tenant = Filament::getTenant();
                        if (! $tenant) {
                            return null;
                        }

                        $pivot = $record->businesses()
                            ->where('businesses.id', $tenant->getKey())
                            ->first()
                            ?->pivot;

                        return $pivot?->role;
                    })
                    ->formatStateUsing(fn (?string $state): string => BusinessRole::label($state))
                    ->color(fn (?string $state): string => BusinessRole::color($state)),

                Tables\Columns\TextColumn::make('branch')
                    ->label('Sucursal')
                    ->placeholder('—')
                    ->toggleable()
                    ->getStateUsing(function (User $record): ?string {
                        $tenant = Filament::getTenant();
                        if (! $tenant) {
                            return null;
                        }

                        $branchId = $record->businesses()
                            ->where('businesses.id', $tenant->getKey())
                            ->first()
                            ?->pivot
                            ?->branch_id;

                        if (! $branchId) {
                            return null;
                        }

                        return \App\Models\Branch::query()
                            ->whereKey($branchId)
                            ->value('name');
                    }),

                Tables\Columns\IconColumn::make('pivot_active')
                    ->label('Activo')
                    ->boolean()
                    ->getStateUsing(function (User $record): bool {
                        $tenant = Filament::getTenant();
                        if (! $tenant) {
                            return (bool) ($record->is_active ?? true);
                        }

                        $pivot = $record->businesses()
                            ->where('businesses.id', $tenant->getKey())
                            ->first()
                            ?->pivot;

                        return (bool) ($pivot?->is_active ?? false);
                    }),

                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('Último acceso')
                    ->dateTime('d/m/Y h:i a')
                    ->placeholder('Nunca')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label('Puesto')
                    ->options(BusinessRole::options())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! filled($value)) {
                            return $query;
                        }
                        $tenantId = Filament::getTenant()?->getKey();

                        return $query->whereHas('businesses', function (Builder $q) use ($tenantId, $value): void {
                            $q->where('businesses.id', $tenantId)
                                ->where('user_business.role', $value);
                        });
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Editar'),
                Tables\Actions\Action::make('toggle_activo')
                    ->label(fn (User $record): string => static::pivotIsActive($record) ? 'Desactivar' : 'Activar')
                    ->icon(fn (User $record): string => static::pivotIsActive($record)
                        ? 'heroicon-o-lock-closed'
                        : 'heroicon-o-lock-open')
                    ->color(fn (User $record): string => static::pivotIsActive($record) ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record): string => static::pivotIsActive($record)
                        ? 'Desactivar a este empleado'
                        : 'Activar a este empleado')
                    ->modalDescription(fn (User $record): string => static::pivotIsActive($record)
                        ? 'No podrá entrar a este comercio. Tú y el resto del equipo siguen dentro.'
                        : 'Vuelve a poder entrar a este comercio.')
                    ->visible(fn (User $record): bool => static::canEdit($record)
                        && (string) $record->getKey() !== (string) auth()->id())
                    ->action(function (User $record): void {
                        static::togglePivotActive($record);
                    }),
                Tables\Actions\Action::make('clave_temporal')
                    ->label('Clave temporal')
                    ->icon('heroicon-o-key')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Nueva clave temporal')
                    ->modalDescription('Se genera una clave nueva. Entrégasela al empleado. Su sesión actual se cierra; la tuya no.')
                    ->visible(fn (User $record): bool => static::canEdit($record))
                    ->action(function (User $record): void {
                        static::resetTemporaryPassword($record);
                    }),
                Tables\Actions\Action::make('quitar_del_negocio')
                    ->label('Quitar del negocio')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Quitar usuario de este negocio')
                    ->modalDescription('No se borra la cuenta global; solo se desvincula de este comercio.')
                    ->visible(fn (User $record): bool => static::canRemoveFromBusiness($record))
                    ->action(function (User $record): void {
                        $tenant = Filament::getTenant();
                        if (! $tenant) {
                            return;
                        }

                        if ((string) $record->getKey() === (string) auth()->id()) {
                            Notification::make()
                                ->title('No puedes quitarte a ti mismo')
                                ->danger()
                                ->send();

                            return;
                        }

                        // No dejar el negocio sin dueño
                        $role = $record->businesses()
                            ->where('businesses.id', $tenant->getKey())
                            ->first()
                            ?->pivot
                            ?->role;

                        if ($role === 'owner') {
                            $owners = User::query()
                                ->whereHas('businesses', function (Builder $q) use ($tenant): void {
                                    $q->where('businesses.id', $tenant->getKey())
                                        ->where('user_business.role', 'owner')
                                        ->where('user_business.is_active', true);
                                })
                                ->count();

                            if ($owners <= 1) {
                                Notification::make()
                                    ->title('Debe quedar al menos un dueño')
                                    ->danger()
                                    ->send();

                                return;
                            }
                        }

                        $record->businesses()->detach($tenant->getKey());

                        \App\Models\AuditLog::log(
                            event: 'business_user.detached',
                            subject: $record,
                            properties: [
                                'business_id' => (string) $tenant->getKey(),
                            ],
                            description: "Usuario {$record->email} desvinculado del negocio",
                            severity: 'warning',
                        );

                        Notification::make()
                            ->title('Usuario quitado del negocio')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Aún no hay personal')
            ->emptyStateDescription('Crea el cajero de la sucursal.')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()
                    ->label('Nuevo usuario'),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        // Solo usuarios del negocio actual
        return parent::getEloquentQuery()
            ->whereHas('businesses', function (Builder $query) use ($tenant): void {
                $query->where('businesses.id', $tenant->getKey());
            })
            ->with(['businesses' => function ($q) use ($tenant): void {
                $q->where('businesses.id', $tenant->getKey());
            }]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBusinessUsers::route('/'),
            'create' => Pages\CreateBusinessUser::route('/create'),
            'edit' => Pages\EditBusinessUser::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = filament()->getTenant();

        return $tenant ? $tenant->hasModule('employees') && static::canViewAny() : false;
    }

    public static function canViewAny(): bool
    {
        $tenant = filament()->getTenant();
        if (! $tenant || ! $tenant->hasModule('employees')) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false;
    }

    public static function canCreate(): bool
    {
        $tenant = filament()->getTenant();
        if (! $tenant || ! $tenant->hasModule('employees')) {
            return false;
        }

        if (! (auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false)) {
            return false;
        }

        return app(\App\Services\SeatLimitService::class)->canAdd($tenant);
    }

    public static function canEdit($record): bool
    {
        $tenant = filament()->getTenant();
        if (! $tenant || ! $tenant->hasModule('employees')) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false;
    }

    public static function canDelete($record): bool
    {
        // No se borra el User global (evita DELETE accidental).
        // Para desvincular del negocio se usa canRemoveFromBusiness + acción “Quitar”.
        return false;
    }

    /**
     * ¿Puede desvincular al usuario del negocio actual?
     * (equivalente a “delete” de negocio, sin borrar la cuenta global)
     */
    public static function canRemoveFromBusiness($record): bool
    {
        $tenant = filament()->getTenant();
        if (! $tenant || ! $tenant->hasModule('employees')) {
            return false;
        }

        if (! (auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false)) {
            return false;
        }

        // Evitar que se quite a sí mismo
        if ($record && (string) $record->getKey() === (string) auth()->id()) {
            return false;
        }

        return true;
    }

    public static function pivotIsActive(User $record): bool
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return (bool) ($record->is_active ?? true);
        }

        return (bool) ($record->businesses()
            ->where('businesses.id', $tenant->getKey())
            ->first()
            ?->pivot
            ?->is_active ?? false);
    }

    protected static function togglePivotActive(User $record): void
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return;
        }

        $tenantId = (string) $tenant->getKey();
        $pivot = $record->businesses()
            ->where('businesses.id', $tenantId)
            ->first()
            ?->pivot;

        $currentlyActive = (bool) ($pivot?->is_active ?? false);
        $role = $pivot?->role;

        if (! $currentlyActive && ! app(\App\Services\SeatLimitService::class)->canAdd($tenant)) {
            Notification::make()
                ->title('Plan de usuarios lleno')
                ->body(app(\App\Services\SeatLimitService::class)->denyMessage($tenant))
                ->danger()
                ->send();

            return;
        }

        if ($currentlyActive && $role === 'owner') {
            $owners = User::query()
                ->whereHas('businesses', function (Builder $q) use ($tenantId): void {
                    $q->where('businesses.id', $tenantId)
                        ->where('user_business.role', 'owner')
                        ->where('user_business.is_active', true);
                })
                ->count();

            if ($owners <= 1) {
                Notification::make()
                    ->title('Debe quedar al menos un dueño activo')
                    ->danger()
                    ->send();

                return;
            }
        }

        $record->businesses()->syncWithoutDetaching([
            $tenantId => [
                'is_active' => ! $currentlyActive,
            ],
        ]);

        RefreshAuthenticatedContext::invalidateFor((string) $record->getKey());

        Notification::make()
            ->title($currentlyActive ? 'Empleado desactivado' : 'Empleado activado')
            ->success()
            ->send();
    }

    protected static function resetTemporaryPassword(User $record): void
    {
        $temp = 'Tmp-'.Str::upper(Str::random(3)).'-'.random_int(1000, 9999).'!';

        $record->forceFill([
            'password' => $temp,
            'password_changed_at' => now(),
        ])->save();

        static::invalidateSessionsForUser((string) $record->getKey());
        RefreshAuthenticatedContext::invalidateFor((string) $record->getKey());

        Notification::make()
            ->title('Clave temporal lista')
            ->body("Entrégasela a {$record->name}: {$temp}")
            ->success()
            ->persistent()
            ->send();
    }

    /**
     * Cierra sesiones de ESA persona en driver database, sin tocar al dueño
     * que está editando (si no es la misma cuenta).
     */
    protected static function invalidateSessionsForUser(string $userId): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $currentSessionId = request()->session()->getId();
        $editorId = (string) (auth()->id() ?? '');

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->when(
                $editorId === $userId && $currentSessionId !== '',
                fn ($q) => $q->where('id', '!=', $currentSessionId),
            )
            ->delete();
    }
}
