<?php

namespace App\Models;

use App\Services\Payments\PassengerPayments\PaymentUpdates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MpesaBookingCallback extends Model
{
    use HasFactory;

    protected $fillable = ['transid', 'name', 'amount', 'points', 'phone', 'transdate', 'booking_id', 'callback', 'redeemed'];

    /**
     * An M-Pesa booking is announced as paid once its receipt is written --
     * see MpesaQrcodePayment::booted for why the receipt and not the flag.
     */
    protected static function booted(): void
    {
        static::created(static fn (self $receipt) => PaymentUpdates::bookingChanged((int) $receipt->booking_id));
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
