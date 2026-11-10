<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['voucher_id', 'code', 'description', 'unit', 'quantity', 'unit_price', 'subtotal', 'igv', 'total'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'subtotal' => 'decimal:2',
            'igv' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }
}
