<?php

namespace App\Filament\Master\Pages;

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\MasterAuditLog;
use App\Services\AuditLogger;
use App\Services\TwoFactorService;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

class SetupTwoFactor extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static string $view = 'filament.master.pages.setup-two-factor';

    protected static ?string $title = 'Configurar autenticación de dos factores (2FA)';

    protected static ?string $slug = 'setup-2fa';

    protected static ?string $navigationLabel = 'Configurar 2FA';

    protected static ?int $navigationSort = 99;

    public ?string $secret = null;

    public ?string $qrSvg = null;

    public ?string $code = null;

    /** @var list<string> */
    public array $recoveryCodes = [];

    public bool $enabled = false;

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        return $user
            && ($user->is_master_admin ?? false)
            && ! $user->hasTwoFactorEnabled();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->enabled
            ? '2FA activado — guarda tus códigos'
            : 'Configurar autenticación de dos factores (2FA)';
    }

    public function mount(TwoFactorService $twoFactor): void
    {
        $user = Auth::user();
        abort_unless($user?->is_master_admin, 403);

        if ($user->hasTwoFactorEnabled() && empty($this->recoveryCodes)) {
            $this->redirect(filament()->getPanel('master')->getUrl());

            return;
        }

        if (! $user->hasTwoFactorEnabled()) {
            $this->secret = session('master.2fa_setup_secret') ?? $twoFactor->generateSecret();
            session(['master.2fa_setup_secret' => $this->secret]);
            $this->qrSvg = $twoFactor->qrCodeSvg($user, $this->secret);
        }

        $this->form->fill(['code' => null]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('code')
                    ->label('Código de verificación de 6 dígitos')
                    ->required()
                    ->numeric()
                    ->length(6)
                    ->autocomplete('one-time-code')
                    ->placeholder('000000'),
            ])
            ->statePath('');
    }

    public function enableTwoFactor(TwoFactorService $twoFactor): void
    {
        $data = $this->form->getState();
        $code = (string) ($data['code'] ?? $this->code ?? '');

        $user = Auth::user();
        $secret = $this->secret ?? session('master.2fa_setup_secret');

        if (! $secret || ! $user) {
            Notification::make()
                ->title('Sesión de configuración expirada')
                ->body('Recarga la página e intenta de nuevo.')
                ->danger()
                ->send();

            return;
        }

        if (! $twoFactor->enable($user, $secret, $code)) {
            Notification::make()
                ->title('Código inválido')
                ->body('Verifica el código de tu app de autenticación.')
                ->danger()
                ->send();

            return;
        }

        session()->forget('master.2fa_setup_secret');
        session()->put(EnsureTwoFactorVerified::SESSION_PASSED, true);

        $this->enabled = true;
        $this->recoveryCodes = $twoFactor->recoveryCodes($user->fresh())->all();
        $this->secret = null;
        $this->qrSvg = null;

        app(AuditLogger::class)->info(
            'security.2fa_enabled',
            '2FA activado (Panel Master) para '.$user->email,
            userId: (string) $user->getKey()
        );

        MasterAuditLog::log(
            'enable_2fa',
            $user,
            [],
            ['two_factor' => true]
        );

        Notification::make()
            ->title('2FA activado correctamente')
            ->body('Guarda los códigos de recuperación en un lugar seguro.')
            ->success()
            ->send();
    }

    public function continueToDashboard(): void
    {
        $this->redirect(filament()->getPanel('master')->getUrl());
    }
}
