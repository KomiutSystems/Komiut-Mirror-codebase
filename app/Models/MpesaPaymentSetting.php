<?php

namespace App\Models;

use App\Casts\EncryptedLegacyString;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One M-Pesa connection: a Daraja app's credentials for a head-office short
 * code. A SACCO can have many (NICCO collects through ~25); buses link to the
 * one their till sits under (vehicles.mpesa_payment_setting_id), and a bus
 * with none falls back to the SACCO's default (is_default).
 *
 * The three credential columns are encrypted at rest and hidden from every JSON
 * response — they must only ever leave the system as an outbound Daraja call.
 * Read masked, non-secret fields through MpesaPaymentSettingResource.
 */
class MpesaPaymentSetting extends Model
{
    use BelongsToSacco, HasFactory;

    protected $fillable = ['sacco_id', 'name', 'is_default', 'consumer_key', 'consumer_secret', 'pass_key', 'business_short_code', 'paybill', 'payment_mode', 'is_live', 'status'];

    /** Never serialize the live credentials. */
    protected $hidden = ['consumer_key', 'consumer_secret', 'pass_key'];

    protected $casts = [
        'consumer_key' => EncryptedLegacyString::class,
        'consumer_secret' => EncryptedLegacyString::class,
        'pass_key' => EncryptedLegacyString::class,
        'is_live' => 'boolean',
        'status' => 'boolean',
        'is_default' => 'boolean',
    ];

    protected static function booted(): void
    {
        // A SACCO's FIRST connection is its default -- the one a bus with no
        // connection of its own falls back to. Later ones are not: adding a
        // second head office must never move every unlinked bus onto it.
        static::creating(function (self $setting): void {
            if ($setting->sacco_id !== null && ! $setting->is_default
                && ! static::withoutGlobalScopes()->where('sacco_id', $setting->sacco_id)->where('is_default', true)->exists()) {
                $setting->is_default = true;
            }
        });
    }

    /**
     * The SACCO's default connection: what MpesaCredentialResolver falls back
     * to for a bus with no connection of its own. Unscoped, like the resolver.
     */
    public static function defaultFor(?int $saccoId): ?self
    {
        if ($saccoId === null) {
            return null;
        }

        return static::withoutGlobalScopes()
            ->where('sacco_id', $saccoId)
            ->where('is_default', true)
            ->orderBy('id')
            ->first();
    }

    /** Has the API keys c2b/v2/registerurl needs. */
    public function canRegisterTills(): bool
    {
        return filled($this->consumer_key) && filled($this->consumer_secret);
    }

    /** Has everything an STK push needs -- what an in-app payment uses. */
    public function canTakeAppPayments(): bool
    {
        return $this->canRegisterTills() && filled($this->pass_key) && filled($this->business_short_code);
    }

    public function sacco()
    {
        return $this->belongsTo(Sacco::class);
    }
}
