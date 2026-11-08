<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Financier;
use App\Enums\WalletSettlementStatus;
use App\Support\Shillings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One payout owed to a SACCO for the wallet fares its buses carried.
 *
 * NOT SCOPED. Like Wallet, this carries no SaccoScope or BrandScope: it is read
 * only by platform staff deciding what to pay, and a tenant scope would hide
 * rows from the one screen whose entire job is to see all of them. Every read
 * is behind the `super` middleware instead.
 */
class WalletSettlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'sacco_id',
        'financier',
        'period_start',
        'period_end',
        'gross_cents',
        'fare_count',
        'status',
        'reference',
        'payout_reference',
        'payout_rail',
        'approved_at',
        'approved_by',
        'paid_at',
        'paid_by',
        'failure_reason',
    ];

    protected $casts = [
        'gross_cents' => 'integer',
        'fare_count' => 'integer',
        'status' => WalletSettlementStatus::class,
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function sacco(): BelongsTo
    {
        return $this->belongsTo(Sacco::class);
    }

    /** The fares this settlement claimed. */
    public function fares(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_settlement_id');
    }

    public function grossShillings(): int
    {
        return Shillings::toShillings((int) $this->gross_cents);
    }

    /** How the bank is named to a human. Null financier = no bank finances these buses. */
    public function bankLabel(): string
    {
        return Financier::tryParse($this->financier)?->label() ?? 'No bank';
    }

    public function isPaid(): bool
    {
        return $this->status === WalletSettlementStatus::Paid;
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'id' => $this->id,
            'sacco' => ['id' => (int) $this->sacco_id, 'name' => $this->sacco?->name],
            'financier' => $this->financier,
            'bank' => $this->bankLabel(),
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'gross' => $this->grossShillings(),
            'fares' => (int) $this->fare_count,
            'status' => $this->status->value,
            'reference' => $this->reference,
            'payout_reference' => $this->payout_reference,
            'payout_rail' => $this->payout_rail,
            'approved_at' => $this->approved_at,
            'paid_at' => $this->paid_at,
            'failure_reason' => $this->failure_reason,
        ];
    }
}
