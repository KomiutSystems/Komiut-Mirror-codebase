<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WalletTopUpRail;
use App\Enums\WalletTopUpStatus;
use App\Support\Shillings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to load money into a wallet.
 *
 * Unlike WalletTransaction this row IS mutable -- it tracks a request through
 * to its outcome. The money it eventually becomes is immutable and lives in the
 * ledger; this is only the story of asking.
 */
class WalletTopUp extends Model
{
    use HasFactory;

    protected $fillable = [
        'wallet_id',
        'amount_cents',
        'rail',
        'status',
        'phone',
        'idempotency_key',
        'rail_reference',
        'callback_nonce',
        'rail_receipt',
        'rail_paid_at',
        'mpesa_id',
        'wallet_transaction_id',
        'failure_reason',
        'completed_at',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'rail' => WalletTopUpRail::class,
        'status' => WalletTopUpStatus::class,
        'completed_at' => 'datetime',
        'rail_paid_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id');
    }

    public function amountShillings(): int
    {
        return Shillings::toShillings((int) $this->amount_cents);
    }

    public function isSettled(): bool
    {
        return $this->wallet_transaction_id !== null;
    }

    /**
     * The body the app parses, from WalletTopUp.fromJson in wallet_data.dart.
     *
     * `balance_after` is null until the money lands, and is read off the LEDGER
     * row rather than off the wallet: by the time a passenger polls, another
     * fare may already have moved the balance, and showing them the current
     * balance as "after this top-up" would misstate what the top-up did.
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amountShillings(),
            'source' => $this->rail->value,
            'phone' => $this->phone,
            'status' => $this->status->value,
            'failure_reason' => $this->failure_reason,
            'balance_after' => $this->relationLoaded('transaction') && $this->transaction !== null
                ? $this->transaction->balanceAfterShillings()
                : ($this->wallet_transaction_id === null
                    ? null
                    : WalletTransaction::whereKey($this->wallet_transaction_id)->first()?->balanceAfterShillings()),
            'created_at' => $this->created_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
