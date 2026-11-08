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

    /**
     * The passenger's receipt number.
     *
     * NOT `reference`, which is the internal idempotency key -- a passenger was
     * being handed `fare:tap:5521` and a top-up row quoted an `mpesas` primary
     * key they could not match against the M-Pesa SMS in their inbox. Derived
     * from columns and `meta` only, so a page of twenty rows costs no queries.
     *
     *   top-up   the M-Pesa receipt, which is what their SMS quotes
     *   tap      WP{payment id}, the number the crew's takings show as QR-WLT-
     *   booking  BKG-{id}, as every other booking reference in this codebase
     *
     * A refund of a fare resolves to the SAME code as the fare it reverses,
     * which is what the app's contract shows and what a passenger querying one
     * ride would expect.
     */
    public function receiptCode(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];

        $transId = trim((string) ($meta['trans_id'] ?? ''));

        // `stk-ws_CO_...` is our own stand-in for a receipt Daraja never named;
        // it is not something a passenger can look up, so it is not shown.
        if ($transId !== '' && ! str_starts_with($transId, 'stk-')) {
            return $transId;
        }

        $paymentId = (int) ($meta['qrcode_payment_id'] ?? 0);

        if ($paymentId > 0) {
            return 'WP'.$paymentId;
        }

        if ($this->booking_id !== null) {
            return 'BKG-'.$this->booking_id;
        }

        // An adjustment or a reversal is only ever its own row.
        return 'WE'.$this->id;
    }
}
