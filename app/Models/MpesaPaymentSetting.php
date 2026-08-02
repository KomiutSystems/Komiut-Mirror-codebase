<?php

namespace App\Models;

use App\Casts\EncryptedLegacyString;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A SACCO's M-Pesa collection credentials. Shared across the SACCO's vehicles;
 * each vehicle only differs by its own till_number / merchant_short_code.
 *
 * The three credential columns are encrypted at rest and hidden from every JSON
 * response — they must only ever leave the system as an outbound Daraja call.
 * Read masked, non-secret fields through MpesaPaymentSettingResource.
 */
class MpesaPaymentSetting extends Model
{
    use BelongsToSacco, HasFactory;

    protected $fillable = ['sacco_id', 'consumer_key', 'consumer_secret', 'pass_key', 'business_short_code', 'paybill', 'payment_mode', 'is_live', 'status'];

    /** Never serialize the live credentials. */
    protected $hidden = ['consumer_key', 'consumer_secret', 'pass_key'];

    protected $casts = [
        'consumer_key' => EncryptedLegacyString::class,
        'consumer_secret' => EncryptedLegacyString::class,
        'pass_key' => EncryptedLegacyString::class,
        'is_live' => 'boolean',
        'status' => 'boolean',
    ];

    public function sacco()
    {
        return $this->belongsTo(Sacco::class);
    }
}
