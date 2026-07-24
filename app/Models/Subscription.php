<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Puts a SACCO on a billing plan and tracks the recurring-invoice cursor
 * (`next_invoice_date`). SACCO-scoped like every tenant-owned model.
 */
class Subscription extends Model
{
    use HasFactory, BelongsToSacco;

    protected $fillable = [
        'sacco_id', 'subscription_type_id', 'amount', 'previous_balance',
        'starts_on', 'next_invoice_date', 'is_active', 'status_id', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'next_invoice_date' => 'date',
        'previous_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function sacco()
    {
        return $this->belongsTo(Sacco::class);
    }

    public function plan()
    {
        return $this->belongsTo(SubscriptionType::class, 'subscription_type_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
}
