<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Billing plan. The invoice for a period is
 *   base_fee + per_vehicle_fee × active vehicles (capped at vehicle_cap).
 */
class SubscriptionType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'description', 'billing_cycle', 'base_fee', 'per_vehicle_fee',
        'vehicle_cap', 'currency', 'is_active', 'status_id', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'base_fee' => 'decimal:2',
        'per_vehicle_fee' => 'decimal:2',
        'vehicle_cap' => 'integer',
        'is_active' => 'boolean',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }
}
