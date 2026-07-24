<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A SACCO's bill for one billing period. Totals are authoritative and set by
 * InvoiceService; payments recompute amount_paid/balance/status. The
 * `invoice_number` doubles as the M-Pesa account reference the SACCO pays to.
 *
 * SACCO-scoped: a SACCO can only ever read its own invoices; superadmins see all.
 */
class Invoice extends Model
{
    use HasFactory, BelongsToSacco;

    protected $fillable = [
        'sacco_id', 'subscription_id', 'invoice_number', 'status',
        'period_start', 'period_end', 'due_date', 'currency',
        'subtotal', 'tax', 'total', 'amount_paid', 'balance',
        'issued_at', 'paid_at', 'notes',
    ];

    protected $casts = [
        'status' => InvoiceStatus::class,
        'period_start' => 'date',
        'period_end' => 'date',
        'due_date' => 'date',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    public function sacco()
    {
        return $this->belongsTo(Sacco::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments()
    {
        return $this->hasMany(InvoicePayment::class);
    }
}
