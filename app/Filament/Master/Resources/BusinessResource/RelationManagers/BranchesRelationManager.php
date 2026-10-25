<?php

namespace App\Filament\Master\Resources\BusinessResource\RelationManagers;

use App\Models\Branch;
use App\Models\User;
use App\Services\SeatLimitService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class BranchesRelationManager extends RelationManager
{
    protected static string $relationship = 'branches';

    protected static ?string $title = 'Sucursales';

    protected static ?string $recordTitleAttribute = 'name';

    /** @var array<string, mixed> */
    protected array $pendingAdmin = [];

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nombre del local')
                ->required()
                ->maxLength(120),
            Forms\Components\TextInput::make('code')
                ->label('Código')
                ->maxLength(20),
            Forms\Components\TextInput::make('phone')
                ->label('Teléfono')
                ->tel()
                ->maxLength(30),
            Forms\Components\TextInput::make('address')
                ->label('Dirección')
                ->maxLength(255)
                ->columnSpanFull(),
            Forms\Components\Toggle::make('is_main')
                ->label('Principal')
                ->default(false),
            Forms\Components\Toggle::make('is_active')
                ->label('Activa')
                ->default(true),
            Forms\Components\Section::make('Administrador de este local')
                ->description('Se crea (o vincula) un admin que solo ve esta sucursal: empleados, mercancía y dashboard de ese punto.')
                ->schema([
                    Forms\Components\TextInput::make('admin_name')
                        ->label('Nombre')
                        ->maxLength(255)
                        ->required(fn (string $operation): bool => $operation === 'create'),
                    Forms\Components\TextInput::make('admin_email')
                        ->label('Correo')
                        ->email()
                        ->required(fn (string $operation): bool => $operation === 'create'),
                    Forms\Components\TextInput::make('admin_password')
                        ->label('Clave temporal')
                        ->password()
                        ->revealable()
                        ->rule(Password::defaults())
                        ->required(fn (string $operation): bool => $operation === 'create'),
                ])
                ->columns(3)
                ->visibleOn('create'),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Local')->weight('bold'),
                Tables\Columns\TextColumn::make('code')->label('Código')->placeholder('—'),
                Tables\Columns\IconColumn::make('is_main')->label('Principal')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('Activa')->boolean(),
                Tables\Columns\TextColumn::make('address')->label('Dirección')->limit(40)->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Activar sucursal')
                    ->mutateFormDataUsing(function (array $data): array {
                        $this->pendingAdmin = [
                            'admin_name' => $data['admin_name'] ?? '',
                            'admin_email' => $data['admin_email'] ?? '',
                            'admin_password' => $data['admin_password'] ?? '',
                        ];
                        unset($data['admin_name'], $data['admin_email'], $data['admin_password']);

                        return $data;
                    })
                    ->after(function (Branch $record): void {
                        $this->provisionBranchAdmin($record, $this->pendingAdmin);
                        $this->pendingAdmin = [];
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function provisionBranchAdmin(Branch $record, array $data): void
    {
        $business = $this->getOwnerRecord();
        $name = trim((string) ($data['admin_name'] ?? ''));
        $email = strtolower(trim((string) ($data['admin_email'] ?? '')));
        $password = (string) ($data['admin_password'] ?? '');

        if ($name === '' || $email === '' || $password === '') {
            return;
        }

        DB::transaction(function () use ($business, $record, $name, $email, $password): void {
            $user = User::query()->where('email', $email)->first();
            if (! $user) {
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                    'is_active' => true,
                    'current_business_id' => $business->getKey(),
                ]);
            }

            $user->businesses()->syncWithoutDetaching([
                $business->getKey() => [
                    'role' => 'admin',
                    'is_active' => true,
                    'branch_id' => $record->getKey(),
                    'joined_at' => now(),
                ],
            ]);

            if (! $alreadySeated && $seats->activeSeats($business->fresh()) > $seats->maxSeats($business->fresh())) {
                $business->forceFill([
                    'extra_seats' => $seats->extraSeats($business) + 1,
                ])->save();
            }
            $alreadySeated = $seats->userOccupiesSeat($business, $user);

            $features = $business->features ?? [];
            $features['strict_branch_scope'] = true;
            $features['strict_branch_stock'] = true;
            $business->forceFill(['features' => $features])->save();

            if ($record->is_main) {
                Branch::query()
                    ->where('business_id', $business->getKey())
                    ->where('id', '!=', $record->getKey())
                    ->update(['is_main' => false]);
            }
        });

        Notification::make()
            ->title('Sucursal activada')
            ->body('El administrador de ese local entra en /admin con el correo indicado. Solo ve esa sucursal.')
            ->success()
            ->send();
    }
}
