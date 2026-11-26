<?php

namespace App\Models;

use App\Services\Payments\PassengerPayments\PaymentUpdates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MpesaQrcodePayment extends Model
{
    use HasFactory;

    protected $fillable = ['transid', 'name', 'amount', 'points', 'phone', 'transdate', 'qrcode_payment_id', 'callback', 'redeemed'];

    /**
     * The raw Daraja callback never leaves the server.
     *
     * It is kept for reconciliation and dispute work, and it is several
     * kilobytes of provider JSON that no screen renders. This relation is now
     * eager-loaded onto the QR payments listing, so without this every row on
     * that page would carry one.
     */
    protected $hidden = ['callback'];

    /**
     * The receipt is what makes an M-Pesa fare announceable as paid: the
     * settlement flips the fare's status a moment before it writes this, and
     * the passenger's push reads "Paid KES 100 to KDN 458N · <receipt>".
     */
    protected static function booted(): void
    {
        static::created(static fn (self $receipt) => PaymentUpdates::qrFareChanged((int) $receipt->qrcode_payment_id));
    }

    public function qrcode_payment()
    {
        return $this->belongsTo(QrcodePayment::class);
    }
}
