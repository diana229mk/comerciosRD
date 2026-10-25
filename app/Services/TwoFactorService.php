<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * 2FA TOTP usando pragmarx/google2fa (+ google2fa-laravel).
 * Por defecto es opcional; solo se fuerza si required_roles / require_master lo indican.
 */
class TwoFactorService
{
    public function __construct(
        protected Google2FA $google2fa = new Google2FA
    ) {
        $this->google2fa->setWindow((int) config('security.two_factor.window', 1));
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function encryptSecret(string $secret): string
    {
        return Crypt::encryptString($secret);
    }

    public function decryptSecret(string $encrypted): string
    {
        return Crypt::decryptString($encrypted);
    }

    public function qrCodeSvg(User $user, string $plainSecret): string
    {
        $issuer = (string) config('security.two_factor.issuer', config('google2fa.otp_issuer', 'ComerciosRD'));
        $otpauth = $this->google2fa->getQRCodeUrl($issuer, $user->email, $plainSecret);

        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd
        );

        return (new Writer($renderer))->writeString($otpauth);
    }

    public function verify(User $user, string $code): bool
    {
        if (blank($user->two_factor_secret)) {
            return false;
        }

        $secret = $this->decryptSecret($user->two_factor_secret);
        $code = preg_replace('/\s+/', '', $code) ?? $code;

        if ($this->google2fa->verifyKey($secret, $code)) {
            return true;
        }

        return $this->verifyRecoveryCode($user, $code);
    }

    public function verifyRecoveryCode(User $user, string $code): bool
    {
        $codes = $this->recoveryCodes($user);

        if ($codes->isEmpty()) {
            return false;
        }

        $normalized = Str::upper(trim($code));
        $match = $codes->first(fn (string $c) => hash_equals($c, $normalized));

        if ($match === null) {
            return false;
        }

        $remaining = $codes->reject(fn (string $c) => hash_equals($c, $normalized))->values();
        $user->forceFill([
            'two_factor_recovery_codes' => Crypt::encryptString($remaining->toJson()),
        ])->saveQuietly();

        return true;
    }

    /**
     * @return Collection<int, string>
     */
    public function recoveryCodes(User $user): Collection
    {
        if (blank($user->two_factor_recovery_codes)) {
            return collect();
        }

        try {
            $json = Crypt::decryptString($user->two_factor_recovery_codes);
            $arr = json_decode($json, true);

            return collect(is_array($arr) ? $arr : []);
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return Collection::times($count, fn () => Str::upper(Str::random(4).'-'.Str::random(4)))
            ->all();
    }

    public function enable(User $user, string $plainSecret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? $code;

        if (! $this->google2fa->verifyKey($plainSecret, $code)) {
            return false;
        }

        $recovery = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $this->encryptSecret($plainSecret),
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode($recovery)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return true;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    public function isEnabled(User $user): bool
    {
        return filled($user->two_factor_secret) && filled($user->two_factor_confirmed_at);
    }

    /**
     * ¿El usuario DEBE tener 2FA según rol / master?
     * Con required_roles vacío y require_master=false → nadie está obligado (opcional).
     */
    public function isRequiredFor(User $user, ?string $businessRole = null): bool
    {
        if (! config('security.two_factor.enabled', true)) {
            return false;
        }

        if ($user->is_master_admin && config('security.two_factor.require_master', false)) {
            return true;
        }

        $required = config('security.two_factor.required_roles', []);

        if (! is_array($required) || $required === []) {
            return false;
        }

        if ($businessRole && in_array($businessRole, $required, true)) {
            return true;
        }

        foreach ($user->businesses()->wherePivot('is_active', true)->get() as $business) {
            $role = $business->pivot->role ?? null;
            if ($role && in_array($role, $required, true)) {
                return true;
            }
        }

        return false;
    }
}
