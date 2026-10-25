<?php

namespace App\Filament\Master\Resources;

use App\Filament\Master\Resources\MasterAuditLogResource\Pages;
use App\Models\MasterAuditLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MasterAuditLogResource extends Resource
{
    protected static ?string $model = MasterAuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Audit Log (Master)';

    protected static ?string $modelLabel = 'Registro de Auditoría';

    protected static ?string $pluralModelLabel = 'Registros de Auditoría';

    protected static ?string $navigationGroup = 'Seguridad';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('action')
                    ->label('Acción')
                    ->disabled(),

                Forms\Components\TextInput::make('ip_address')
                    ->label('Dirección IP')
                    ->disabled(),

                Forms\Components\Textarea::make('old_values')
                    ->label('Valores Anteriores')
                    ->disabled()
                    ->formatStateUsing(fn ($state) => is_array($state)
                        ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        : (string) $state),

                Forms\Components\Textarea::make('new_values')
                    ->label('Valores Nuevos')
                    ->disabled()
                    ->formatStateUsing(fn ($state) => is_array($state)
                        ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        : (string) $state),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Detalle del evento')
                    ->schema([
                        Infolists\Components\TextEntry::make('user.name')
                            ->label('Usuario'),
                        Infolists\Components\TextEntry::make('action')
                            ->label('Acción')
                            ->badge(),
                        Infolists\Components\TextEntry::make('model_type')
                            ->label('Modelo')
                            ->formatStateUsing(fn ($state) => $state ? class_basename($state) : '—'),
                        Infolists\Components\TextEntry::make('model_id')
                            ->label('ID del registro')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('ip_address')
                            ->label('IP'),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Fecha')
                            ->dateTime('d/m/Y H:i:s'),
                        Infolists\Components\TextEntry::make('url')
                            ->label('URL')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('old_values')
                            ->label('Valores anteriores')
                            ->formatStateUsing(fn ($state) => $state
                                ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                                : '—')
                            ->columnSpanFull()
                            ->prose(),
                        Infolists\Components\TextEntry::make('new_values')
                            ->label('Valores nuevos')
                            ->formatStateUsing(fn ($state) => $state
                                ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                                : '—')
                            ->columnSpanFull()
                            ->prose(),
                        Infolists\Components\TextEntry::make('user_agent')
                            ->label('User Agent')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Usuario')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('action')
                    ->label('Acción')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'suspend_business', 'suspend_user', 'delete_user' => 'danger',
                        'activate_business', 'activate_user', 'enable_2fa' => 'success',
                        'reset_password' => 'warning',
                        'create_user' => 'info',
                        default => 'gray',
                    })
                    ->searchable(),

                Tables\Columns\TextColumn::make('model_type')
                    ->label('Modelo')
                    ->formatStateUsing(fn ($state) => $state ? class_basename($state) : '—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->label('Acción')
                    ->options([
                        'suspend_business' => 'Suspender Negocio',
                        'activate_business' => 'Activar Negocio',
                        'reset_password' => 'Resetear Contraseña',
                        'create_user' => 'Crear Usuario',
                        'update_user' => 'Actualizar Usuario',
                        'delete_user' => 'Eliminar Usuario',
                        'suspend_user' => 'Suspender Usuario',
                        'activate_user' => 'Activar Usuario',
                        'enable_2fa' => 'Activar 2FA',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMasterAuditLogs::route('/'),
            'view' => Pages\ViewMasterAuditLog::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // Solo lectura
    }

    public static function canEdit($record): bool
    {
        return false; // Solo lectura
    }

    public static function canDelete($record): bool
    {
        return false; // Solo lectura
    }

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }
}
