<?php

namespace App\Filament\Master\Pages;

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Services\AuditLogger;
use App\Services\TwoFactorService;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ChallengeTwoFactor extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static string $view = 'filament.master.pages.challenge-two-factor';

    protected static ?string $title = 'Verificación en dos pasos';

    protected static ?string $slug = 'challenge-2fa';

    protected static bool $shouldRegisterNavigation = false;

    public ?string $code = null;

    public function mount(TwoFactorService $twoFactor): void
    {
        $user = Auth::user();
        abort_unless($user?->is_master_admin, 403);

        if (! $twoFactor->isEnabled($user)) {
            $this->redirect(SetupTwoFactor::getUrl(panel: 'master'));

            return;
        }

        if (session(EnsureTwoFactorVerified::SESSION_PASSED)) {
            $this->redirect(filament()->getPanel('master')->getUrl());

            return;
        }

        $this->form->fill(['code' => null]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('code')
                    ->label('Código de 6 dígitos o código de recuperación')
                    ->required()
                    ->autocomplete('one-time-code')
                    ->placeholder('000000'),
            ])
            ->statePath('');
    }

    public function verify(TwoFactorService $twoFactor): void
    {
        $data = $this->form->getState();
        $code = (string) ($data['code'] ?? $this->code ?? '');
        $user = Auth::user();

        if (! $user || ! $twoFactor->verify($user, $code)) {
            app(AuditLogger::class)->warning(
                'security.2fa_failed',
                'Código 2FA inválido (Panel Master)',
                userId: $user ? (string) $user->getKey() : null
            );

            Notification::make()
                ->title('Código incorrecto')
                ->danger()
                ->send();

            return;
        }

        session()->put(EnsureTwoFactorVerified::SESSION_PASSED, true);

        app(AuditLogger::class)->info(
            'security.2fa_passed',
            '2FA verificado en Panel Master',
            userId: (string) $user->getKey()
        );

        Notification::make()
            ->title('Verificación correcta')
            ->success()
            ->send();

        $this->redirect(filament()->getPanel('master')->getUrl());
    }
}
