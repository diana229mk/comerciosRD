<?php

namespace App\Enums;

enum EcfEnvironment: string
{
    case Test = 'test';
    case Cert = 'cert';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Test => 'TesteCF (pruebas)',
            self::Cert => 'CerteCF (certificación)',
            self::Production => 'Producción (DGII)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Test => 'Ambiente de pruebas de la DGII. No tiene validez fiscal.',
            self::Cert => 'Ambiente de certificación. Requerido antes de producción.',
            self::Production => 'Emisión real con validez fiscal ante la DGII.',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
