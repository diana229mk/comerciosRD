<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Política de contraseñas fuerte (mín. 10, mayúscula, minúscula, número, símbolo).
 */
class StrongPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $password = (string) $value;
        $cfg = config('security.password', []);

        $min = (int) ($cfg['min_length'] ?? 10);

        if (strlen($password) < $min) {
            $fail("La contraseña debe tener al menos {$min} caracteres.");

            return;
        }

        if (($cfg['require_uppercase'] ?? true) && ! preg_match('/[A-ZÁÉÍÓÚÑ]/u', $password)) {
            $fail('La contraseña debe incluir al menos una letra mayúscula.');

            return;
        }

        if (($cfg['require_lowercase'] ?? true) && ! preg_match('/[a-záéíóúñ]/u', $password)) {
            $fail('La contraseña debe incluir al menos una letra minúscula.');

            return;
        }

        if (($cfg['require_number'] ?? true) && ! preg_match('/[0-9]/', $password)) {
            $fail('La contraseña debe incluir al menos un número.');

            return;
        }

        if (($cfg['require_symbol'] ?? true) && ! preg_match('/[\W_]/', $password)) {
            $fail('La contraseña debe incluir al menos un símbolo (ej. !@#$%).');
        }
    }
}
