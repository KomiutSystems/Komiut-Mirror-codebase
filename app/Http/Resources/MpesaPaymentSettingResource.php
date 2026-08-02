<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The web-facing shape of a SACCO's M-Pesa settings. Emits the non-secret
 * configuration and a boolean per credential so the dashboard can show
 * "configured" without ever receiving the value. The credentials themselves
 * (consumer key/secret, passkey) are never serialized.
 */
class MpesaPaymentSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sacco_id' => $this->sacco_id,
            'business_short_code' => $this->business_short_code,
            'paybill' => $this->paybill,
            'payment_mode' => $this->payment_mode,
            'is_live' => (bool) $this->is_live,
            'status' => (bool) $this->status,

            // Whether each credential is set — the value is never exposed.
            'consumer_key_set' => filled($this->consumer_key),
            'consumer_secret_set' => filled($this->consumer_secret),
            'pass_key_set' => filled($this->pass_key),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
