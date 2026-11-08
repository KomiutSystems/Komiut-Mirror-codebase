<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WalletStatus;
use App\Support\Shillings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A passenger's prepaid balance.
 *
 * NO BelongsToSacco AND NO BelongsToBrand, unlike nearly every other model
 * here. That is the point of the design: one balance, spendable on any bus, so
 * there is nothing for a tenant scope to filter by. A scope would also be
 * actively dangerous on this table -- a wallet silently invisible to a tenant
 * query is a balance that reads as zero, and a zero balance is a refused fare.
 *
 * Reads are therefore always keyed on `user_id` and nothing else, and every
 * caller is responsible for that being auth()->id(). The routes are passenger
 * self-service only for exactly this reason.
 */
class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'balance_cents',
        'status',
        'tap_limit_cents',
        'float_bank',
    ];

    protected $casts = [
        'balance_cents' => 'integer',
        'tap_limit_cents' => 'integer',
        'frozen_at' => 'datetime',
        'status' => WalletStatus::class,
    ];

    /**
     * The PIN hash must never reach a response or a log. Hidden here as well as
     * being absent from every resource, because `->toArray()` on a model is one
     * forgotten `dd()` away from a payload.
     */
    protected $hidden = ['pin_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /** The balance in whole shillings, floored. See App\Support\Shillings. */
    public function balanceShillings(): int
    {
        return Shillings::toShillings((int) $this->balance_cents);
    }

    public function tapLimitShillings(): int
    {
        return Shillings::toShillings((int) $this->tap_limit_cents);
    }

    public function hasPin(): bool
    {
        return $this->pin_hash !== null;
    }

    /** Mirrors the app's own `canPay`: frozen wallets read and receive, but do not pay. */
    public function canPay(): bool
    {
        return $this->status instanceof WalletStatus && $this->status->canPay();
    }

    /**
     * Whether this amount needs the PIN.
     *
     * STRICTLY GREATER THAN, matching the app's `needsPin(amount) => amount >
     * tapLimit` exactly. A fare equal to the limit taps without a PIN on both
     * sides; an off-by-one here would show a PIN sheet the backend then rejects
     * as unnecessary, or worse, take a fare the app promised would need one.
     *
     * A wallet with no PIN set cannot be asked for one, so it is held to the
     * limit instead -- the caller refuses the fare rather than prompting for a
     * PIN that does not exist.
     */
    public function needsPin(int $amountCents): bool
    {
        return $amountCents > (int) $this->tap_limit_cents;
    }
}
