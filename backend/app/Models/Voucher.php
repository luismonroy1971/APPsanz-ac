<?php

namespace App\Models;

use App\Models\Concerns\ScopedToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Voucher extends Model
{
    use ScopedToClient;

    public const DOCUMENT_TYPES = [
        '01' => 'Factura',
        '03' => 'Boleta de venta',
        '07' => 'Nota de crédito',
        '08' => 'Nota de débito',
    ];

    protected $fillable = [
        'client_id', 'document_id', 'period_id', 'operation', 'document_type', 'series', 'number', 'issue_date',
        'due_date', 'issuer_ruc', 'issuer_name', 'customer_doc_type', 'customer_doc', 'customer_name', 'currency',
        'exchange_rate', 'taxable_amount', 'exempt_amount', 'unaffected_amount', 'igv', 'isc', 'other_charges',
        'total', 'reference_doc', 'status', 'payment_status', 'amount_paid', 'created_by',
    ];

    protected $appends = ['full_number', 'document_type_name'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'exchange_rate' => 'decimal:3',
            'taxable_amount' => 'decimal:2',
            'exempt_amount' => 'decimal:2',
            'unaffected_amount' => 'decimal:2',
            'igv' => 'decimal:2',
            'isc' => 'decimal:2',
            'other_charges' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
        ];
    }

    public function getFullNumberAttribute(): string
    {
        return $this->series.'-'.$this->number;
    }

    public function getDocumentTypeNameAttribute(): string
    {
        return self::DOCUMENT_TYPES[$this->document_type] ?? (string) $this->document_type;
    }

    /** Contraparte: proveedor en compras, cliente en ventas. */
    public function counterpartDoc(): ?string
    {
        return $this->operation === 'compra' ? $this->issuer_ruc : $this->customer_doc;
    }

    public function counterpartName(): ?string
    {
        return $this->operation === 'compra' ? $this->issuer_name : $this->customer_name;
    }

    /** Monto en soles (moneda funcional). Las NC restan. */
    public function signedPen(string $field): float
    {
        $sign = $this->document_type === '07' ? -1 : 1;

        return round($sign * (float) $this->{$field} * (float) $this->exchange_rate, 2);
    }

    public function items(): HasMany
    {
        return $this->hasMany(VoucherItem::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function journalEntry(): HasOne
    {
        return $this->hasOne(JournalEntry::class);
    }
}
