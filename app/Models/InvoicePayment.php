<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A payment received against an invoice (M-Pesa C2B, or a manual reconciliation
 * entry). `mpesa_receipt` is unique so replayed callbacks can't double-credit.
 */
class InvoicePayment extends Model
{
    use HasFactory, BelongsToSacco;

    protected $fillable = [
        'invoice_id', 'sacco_id', 'amount', 'method', 'mpesa_receipt', 'phone', 'paid_at', 'raw',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'raw' => 'array',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
