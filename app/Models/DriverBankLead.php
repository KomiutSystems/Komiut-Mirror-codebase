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
 * banking relationship, and is exported across every SACCO on it. A SACCO admin
 * therefore never sees these rows, which is what keeps a driver's account number
 * and ID out of their employer's dashboard.
 *
 * This is no longer only a lead list -- it now carries the driver's account
 * number and their consent to share it. See the
 * add_bank_account_and_consent_to_driver_bank_leads migration.
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
        // The driver's own NCBA account, captured at onboarding when they
        // already have one -- the bank's app lets them open it themselves.
        'account_number',
        // Consent standing in for a signature; see the migration for why a
        // boolean alone would not do.
        'consent_given_at',
        'consent_text_version',
        'consent_agent',
        'consent_ip',
        'account_opened_at',
    ];

    protected $casts = [
        'bank' => BankPartner::class,
        'opted_in_at' => 'datetime',
        'consent_given_at' => 'datetime',
        'account_opened_at' => 'datetime',
        'vehicle_capacity' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
