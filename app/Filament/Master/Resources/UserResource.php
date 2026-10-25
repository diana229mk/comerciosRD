<?php

namespace App\Filament\Master\Resources;

use App\Filament\Master\Resources\UserResource\Pages;
use App\Models\MasterAuditLog;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Usuarios del Sistema';

    protected static ?string $modelLabel = 'Usuario';

    protected static ?string $pluralModelLabel = 'Usuarios';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('email')
                    ->label('Correo Electrónico')
                    ->email()
                    ->required()
                    ->maxLength(255),

                Forms\Components\Toggle::make('is_master_admin')
                    ->label('Es Administrador Master')
                    ->helperText('Solo los administradores Master pueden acceder al Panel Master'),

                Forms\Components\Toggle::make('is_active')
                    ->label('Usuario Activo')
                    ->default(true),
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

                Tables\Columns\TextColumn::make('businesses.name')
                    ->label('Negocio(s)')
                    ->badge()
                    ->separator(',')
                    ->limitList(3),

                Tables\Columns\IconColumn::make('is_master_admin')
                    ->label('Master Admin')
                    ->boolean()
                    ->trueIcon('heroicon-o-shield-check')
                    ->falseIcon('heroicon-o-user')
                    ->trueColor('danger')
                    ->falseColor('gray'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Registrado')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_master_admin')
                    ->label('Administradores Master'),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Usuarios Activos'),

                Tables\Filters\SelectFilter::make('business')
                    ->label('Negocio')
                    ->relationship('businesses', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\Action::make('reset_password')
                    ->label('Resetear Contraseña')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('new_password')
                            ->label('Nueva Contraseña')
                            ->password()
                            ->required()
                            ->minLength(8)
                            ->revealable(),
                    ])
                    ->action(function (User $record, array $data) {
                        // Cast 'hashed' en User: no usar Hash::make (evitaría doble hash).
                        $record->update([
                            'password' => $data['new_password'],
                            'password_changed_at' => now(),
                        ]);

                        // No guardamos el password anterior por seguridad
                        MasterAuditLog::log(
                            'reset_password',
                            $record,
                            [],
                            [
                                'password_changed' => true,
                                'password_changed_at' => now()->toIso8601String(),
                            ]
                        );

                        \Filament\Notifications\Notification::make()
                            ->title('Contraseña actualizada correctamente')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('suspender')
                    ->label('Suspender')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        $record->update(['is_active' => false]);

                        MasterAuditLog::log(
                            'suspend_user',
                            $record,
                            ['is_active' => true],
                            ['is_active' => false]
                        );

                        \Filament\Notifications\Notification::make()
                            ->title('Usuario suspendido')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (User $record) => $record->is_active),

                Tables\Actions\Action::make('activar')
                    ->label('Activar')
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        $record->update(['is_active' => true]);

                        MasterAuditLog::log(
                            'activate_user',
                            $record,
                            ['is_active' => false],
                            ['is_active' => true]
                        );

                        \Filament\Notifications\Notification::make()
                            ->title('Usuario activado')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (User $record) => ! $record->is_active),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function ($records) {
                            foreach ($records as $record) {
                                MasterAuditLog::log(
                                    'delete_user',
                                    $record,
                                    $record->only(['name', 'email', 'is_master_admin', 'is_active']),
                                    []
                                );
                            }
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
