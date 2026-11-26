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
        "provider",
        "phone",
        "callback",
        "processed_at",
        "cancelled_at",
        "result_code",
        "result_desc",
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** Pushed through NCBA Till STK Push rather than Safaricom's own: settled by query, not callback. */
    public const PROVIDER_NCBA = 'ncba';

    /**
     * Pushed through Co-operative Bank's STK API. `callback_nonce` holds OUR
     * MessageReference for it -- what the bank echoes on its callback and what
     * an enquiry is asked by -- so the outcome can be learned even when the
     * push request itself got no answer.
     */
    public const PROVIDER_COOP = 'coop';

    /**
     * A callback may only be applied once. Daraja retries, and a replayed
     * payload must not mark a second payment or award duplicate points.
     */
    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    public function isNcba(): bool
    {
        return $this->provider === self::PROVIDER_NCBA;
    }

    public function isCoop(): bool
    {
        return $this->provider === self::PROVIDER_COOP;
    }
}
