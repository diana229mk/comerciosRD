<?php

namespace App\Filament\Pages;

use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\Rules\Password;

class ChangePassword extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'Cambiar Contraseña';

    protected static ?string $title = 'Cambiar Contraseña';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.change-password';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Seguridad de la cuenta')
                    ->description('Usa una contraseña fuerte. No la compartas con nadie.')
                    ->schema([
                        Forms\Components\TextInput::make('current_password')
                            ->label('Contraseña Actual')
                            ->password()
                            ->revealable()
                            ->required()
                            ->rule('current_password')
                            ->validationMessages([
                                'current_password' => 'La contraseña actual no es correcta.',
                            ]),

                        Forms\Components\TextInput::make('password')
                            ->label('Nueva Contraseña')
                            ->password()
                            ->revealable()
                            ->required()
                            ->rule(Password::defaults())
                            ->different('current_password')
                            ->confirmed()
                            ->helperText('Debe cumplir la política de seguridad del sistema.'),

                        Forms\Components\TextInput::make('password_confirmation')
                            ->label('Confirmar Nueva Contraseña')
                            ->password()
                            ->revealable()
                            ->required()
                            ->dehydrated(false),
                    ])
                    ->columns(1),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $user = auth()->user();
        if (! $user) {
            return;
        }

        // El cast 'hashed' del User cifra al guardar (no usar Hash::make aquí)
        $user->forceFill([
            'password' => $data['password'],
            'password_changed_at' => now(),
        ])->save();

        Notification::make()
            ->title('Contraseña actualizada correctamente')
            ->success()
            ->send();

        $this->form->fill();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->check();
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
