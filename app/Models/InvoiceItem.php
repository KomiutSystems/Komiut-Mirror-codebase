<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single line on an invoice (base subscription fee, per-vehicle fee, …).
 */
class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = ['invoice_id', 'description', 'quantity', 'unit_price', 'amount'];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
