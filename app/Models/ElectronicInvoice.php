<?php

namespace App\Models;

use App\Enums\EcfType;
use App\Enums\ElectronicInvoiceStatus;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Comprobante fiscal electrónico (e-CF) — base interna.
 * Emisión a DGII se implementará en una fase posterior.
 */
class ElectronicInvoice extends Model
{
    use BelongsToBusiness;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        // business_id: trait
        'sale_id',
        'references_invoice_id',
        'references_ecf_number',
        'modification_code',
        'modification_reason',
        'customer_id',
        'ecf_type',
        'ecf_number',
        'sequence_number',
        'issuer_rnc',
        'issuer_name',
        'buyer_rnc',
        'buyer_name',
        'buyer_email',
        'subtotal',
        'discount',
        'tax',
        'total',
        'status',
        'track_id',
        'security_code',
        'dgii_response',
        'sent_at',
        'accepted_at',
        'xml_content',
        'signed_xml_path',
        'submission_environment',
        'last_submission_at',
        'submission_attempts',
        'pdf_path',
        'issue_date',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'ecf_type' => EcfType::class,
            'status' => ElectronicInvoiceStatus::class,
            'sequence_number' => 'integer',
            'modification_code' => 'integer',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'issue_date' => 'date',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ElectronicInvoiceItem::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * e-CF original al que se refiere esta NC/ND (tipo 34/33).
     */
    public function referencedInvoice(): BelongsTo
    {
        return $this->belongsTo(ElectronicInvoice::class, 'references_invoice_id');
    }

    public function isCreditNote(): bool
    {
        $type = $this->ecf_type;
        $val = $type instanceof EcfType ? $type->value : (string) $type;

        return $val === EcfType::NOTA_CREDITO->value;
    }

    public function modificationCodeLabel(): ?string
    {
        return match ((int) $this->modification_code) {
            1 => 'Anulación',
            2 => 'Corrección de información',
            3 => 'Devolución de mercancía',
            4 => 'Descuentos',
            default => null,
        };
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeIssued(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ElectronicInvoiceStatus::Issued->value,
            ElectronicInvoiceStatus::Sent->value,
            ElectronicInvoiceStatus::Accepted->value,
        ]);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', ElectronicInvoiceStatus::Draft->value);
    }

    public function isDraft(): bool
    {
        return $this->status === ElectronicInvoiceStatus::Draft
            || (string) $this->status === ElectronicInvoiceStatus::Draft->value
            || (string) $this->status === 'draft';
    }

    public function isAccepted(): bool
    {
        return $this->status === ElectronicInvoiceStatus::Accepted
            || (string) $this->status === ElectronicInvoiceStatus::Accepted->value
            || (string) $this->status === 'accepted';
    }

    public function statusLabel(): string
    {
        $status = $this->status;

        return $status instanceof ElectronicInvoiceStatus
            ? $status->label()
            : (string) $status;
    }

    public function typeLabel(): string
    {
        $type = $this->ecf_type;

        return $type instanceof EcfType ? $type->label() : (string) $type;
    }

    protected static function booted(): void
    {
        static::creating(function (ElectronicInvoice $invoice): void {
            if (empty($invoice->created_by) && auth()->check()) {
                $invoice->created_by = auth()->id();
            }

            if (empty($invoice->issue_date)) {
                $invoice->issue_date = now()->toDateString();
            }

            if (empty($invoice->status)) {
                $invoice->status = ElectronicInvoiceStatus::Draft;
            }
        });
    }
}
