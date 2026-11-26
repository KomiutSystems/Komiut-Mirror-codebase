<?php

namespace App\Models;

use App\Enums\LoyaltyTransactionType;
use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToFinancier;
use App\Models\Concerns\BelongsToSacco;
use App\Services\Payments\PassengerPayments\PaymentUpdates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class QrcodePayment extends Model
{
    use BelongsToBrand, BelongsToFinancier, BelongsToSacco, HasFactory;

    /**
     * Readable by a caller with no SACCO of their own. See
     * BelongsToSacco::allowsCrossTenantBrowsing() — it exempts the TENANTLESS
     * caller only; a user who has a SACCO is still filtered to it.
     *
     * The passenger's OWN receipts — and only those. Like Booking, the boundary
     * here is identity, not tenancy: QrcodePaymentsController narrows a saccoless
     * caller to `user_id = auth()->id()` and will not let them widen it by
     * naming a SACCO. Closed, a passenger could not see the payment they had
     * just made.
     */
    protected bool $saccoCrossTenantBrowsing = true;

    /** Reaches the financed vehicle the same way $saccoVia does. */
    protected ?string $financierVia = 'vehicle';

    /** Reaches brand via vehicle. */
    protected ?string $brandVia = 'vehicle';

    /** Reaches sacco_id via the vehicle relation. */
    protected $saccoVia = 'vehicle';

    protected $fillable = ['vehicle_id', 'seat_arrangement_id', 'user_id', 'amount', 'fare', 'payment_method', 'idempotency_key', 'status'];

    /** Bookkeeping for payment.updated (PaymentUpdates), not something a screen reads. */
    protected $hidden = ['payment_announced'];

    /**
     * Tell the passenger's phone when this fare's status moves: created
     * already paid (points, wallet), or flipped to paid by a settlement. An
     * M-Pesa fare written unpaid is not announced here -- its prompt is, the
     * moment the push row exists (MpesaStkCallback::booted).
     */
    protected static function booted(): void
    {
        static::created(static function (self $fare): void {
            if ((bool) $fare->status) {
                PaymentUpdates::qrFareChanged((int) $fare->id);
            }
        });

        static::updated(static function (self $fare): void {
            if ($fare->wasChanged('status')) {
                PaymentUpdates::qrFareChanged((int) $fare->id);
            }
        });
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function seat_arrangement()
    {
        return $this->belongsTo(SeatArrangement::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function mpesa_qrcode_payment()
    {
        return $this->hasOne(MpesaQrcodePayment::class);
    }

    /** The prompt that decides an unpaid fare: the most recent one raised for it. */
    public function latestStkPush(): HasOne
    {
        return $this->hasOne(MpesaStkCallback::class)->latestOfMany();
    }

    /**
     * What a points fare cost, on the loyalty ledger: keyed by source, as
     * LoyaltyService::redeemForVehicle writes it. Load it without global
     * scopes -- LoyaltyTransaction is SACCO-scoped and a passenger has none.
     */
    public function pointsRedemption(): HasOne
    {
        return $this->hasOne(LoyaltyTransaction::class, 'source_id')
            ->where('source_type', 'qrcode_payment')
            ->where('type', LoyaltyTransactionType::Redeemed->value);
    }
}
