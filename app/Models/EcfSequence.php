<?php

namespace App\Models;

use App\Enums\EcfType;
use App\Support\EcfFeature;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Rango de numeración fiscal autorizado por la DGII para un tipo de e-CF.
 */
class EcfSequence extends Model
{
    use BelongsToBusiness;
    use HasUuids;

    protected $fillable = [
        // business_id: trait
        'ecf_type',
        'series',
        'from_number',
        'to_number',
        'current_number',
        'valid_from',
        'valid_until',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'ecf_type' => EcfType::class,
            'from_number' => 'integer',
            'to_number' => 'integer',
            'current_number' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string|EcfType $type): Builder
    {
        $value = $type instanceof EcfType ? $type->value : $type;

        return $query->where('ecf_type', $value);
    }

    public function hasAvailableNumbers(): bool
    {
        return (bool) $this->is_active
            && (int) $this->current_number < (int) $this->to_number;
    }

    public function remainingNumbers(): int
    {
        return max(0, (int) $this->to_number - (int) $this->current_number);
    }

    /** Alias */
    public function remaining(): int
    {
        return $this->remainingNumbers();
    }

    public function isExhausted(): bool
    {
        return $this->remainingNumbers() <= 0;
    }

    public function isValidOn(?\DateTimeInterface $date = null): bool
    {
        $date = $date ? \Illuminate\Support\Carbon::parse($date) : now();

        if ($this->valid_from && $date->lt($this->valid_from->copy()->startOfDay())) {
            return false;
        }

        if ($this->valid_until && $date->gt($this->valid_until->copy()->endOfDay())) {
            return false;
        }

        return true;
    }

    /**
     * Reserva el siguiente número del rango (dentro de TX del llamador).
     *
     * @return array{sequence_number: int, ecf_number: string}
     */
    public function allocateNextNumber(): array
    {
        EcfFeature::ensureEnabled();

        if (! $this->is_active) {
            throw new RuntimeException('La secuencia fiscal no está activa.');
        }

        if (! $this->isValidOn()) {
            throw new RuntimeException('La secuencia fiscal está fuera de vigencia.');
        }

        $next = max((int) $this->current_number + 1, (int) $this->from_number);

        if ($next > (int) $this->to_number) {
            throw new RuntimeException(
                'Se agotó el rango fiscal del e-CF tipo '.$this->ecfTypeValue().'.'
            );
        }

        $this->forceFill(['current_number' => $next])->save();

        return [
            'sequence_number' => $next,
            'ecf_number' => $this->formatEcfNumber($next),
        ];
    }

    public function formatEcfNumber(int $sequenceNumber): string
    {
        // Formato: E + tipo + número 10 dígitos → E320000000001
        return 'E'.$this->ecfTypeValue().str_pad((string) $sequenceNumber, 10, '0', STR_PAD_LEFT);
    }

    public function ecfTypeValue(): string
    {
        $type = $this->ecf_type;

        return $type instanceof EcfType ? $type->value : (string) $type;
    }

    public function typeLabel(): string
    {
        $type = $this->ecf_type;

        return $type instanceof EcfType ? $type->label() : (string) $type;
    }
}
