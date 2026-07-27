<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BankPartner;
use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A driver's interest in opening an account with the brand's partner bank,
 * captured during street onboarding. See the create_driver_bank_leads migration.
 *
 * Brand-scoped, not SACCO-scoped: the list belongs to the brand that owns the
 * banking relationship, and is exported across every SACCO on it.
 */
class DriverBankLead extends Model
{
    use BelongsToBrand, HasFactory;

    protected $fillable = [
        'user_id',
        'brand',
        'bank',
        'preferred_branch',
        'vehicle_capacity',
        'opted_in_at',
        'status',
    ];

    protected $casts = [
        'bank' => BankPartner::class,
        'opted_in_at' => 'datetime',
        'vehicle_capacity' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
