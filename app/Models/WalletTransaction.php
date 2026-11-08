<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WalletEntryKind;
use App\Support\Shillings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement on a wallet. APPEND-ONLY.
 *
 * Nothing in this codebase may update or delete a row here. A mistake is
 * corrected by writing a Reversal with the opposite sign, so that the history a
 * passenger reads is the history that happened -- an edited ledger cannot be
 * reconciled against the bank, and a deleted row makes money vanish with no
 * trace of who removed it.
 */
class WalletTransaction extends Model
{
    use HasFactory;

    public const DIRECTION_CREDIT = 'credit';

    public const DIRECTION_DEBIT = 'debit';

    protected $fillable = [
        'wallet_id',
        'kind',
        'amount_cents',
        'balance_after_cents',
        'title',
        'detail',
        'reference',
        'booking_id',
        'vehicle_id',
        'sacco_id',
        'financier',
        'mpesa_id',
        'actor_id',
        'meta',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'balance_after_cents' => 'integer',
        'kind' => WalletEntryKind::class,
        'meta' => 'array',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function isCredit(): bool
    {
        return (int) $this->amount_cents > 0;
    }

    /** The wire's `direction`. Derived from the sign, never from the kind. */
    public function direction(): string
    {
        return $this->isCredit() ? self::DIRECTION_CREDIT : self::DIRECTION_DEBIT;
    }

    /**
     * The magnitude in whole shillings. Always positive: the app reads
     * `direction` for the sign and would render "-KES -50" if this were signed.
     */
    public function amountShillings(): int
    {
        return Shillings::toShillings(abs((int) $this->amount_cents));
    }

    public function balanceAfterShillings(): int
    {
        return Shillings::toShillings((int) $this->balance_after_cents);
    }
}
