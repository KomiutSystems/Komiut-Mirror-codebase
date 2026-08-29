<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One passenger's platform-wide carbon credit balance.
 *
 * No sacco_id and no brand: this is the platform's reward, and a passenger who
 * rides a komiut bus and a safiri bus builds one balance. Per-SACCO points are
 * LoyaltyAccount, which is a different thing funded by a different party.
 */
class CarbonCreditAccount extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'credits', 'progress_cents', 'lifetime_spend_cents'];

    protected $casts = [
        'credits' => 'integer',
        'progress_cents' => 'integer',
        'lifetime_spend_cents' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Shillings still to travel before the next credit. */
    public function shillingsToNextCredit(): float
    {
        $per = max(1, (int) config('carbon_credits.ksh_per_credit', 1000)) * 100;

        return round(max(0, $per - $this->progress_cents) / 100, 2);
    }
}
