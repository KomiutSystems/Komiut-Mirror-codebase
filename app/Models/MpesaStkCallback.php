<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MpesaStkCallback extends Model
{
    use HasFactory;

    protected $fillable = [
        "booking_id",
        "qrcode_payment_id",
        "callback_nonce",
        "checkout_request_id",
        "callback",
        "processed_at",
        "cancelled_at",
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * A callback may only be applied once. Daraja retries, and a replayed
     * payload must not mark a second payment or award duplicate points.
     */
    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}
