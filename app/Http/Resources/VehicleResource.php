<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract-capture of the current Vehicle JSON shape.
 *
 * Keys derived from the vehicles table migration (2023_07_10_121242) plus the
 * mpesa_payment_setting_id alter (2024_08_17), id + timestamps.
 */
class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plate' => $this->plate,
            'fleet_no' => $this->fleet_no,
            'till_number' => $this->till_number,
            'merchant_short_code' => $this->merchant_short_code,
            'sacco_id' => $this->sacco_id,
            'user_id' => $this->user_id,
            'seat_id' => $this->seat_id,
            'mpesa_payment_setting_id' => $this->mpesa_payment_setting_id,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'user' => $this->whenLoaded('user'),
            'seat' => $this->whenLoaded('seat'),
            'sacco' => $this->whenLoaded('sacco'),
            'vehicle_user' => $this->whenLoaded('vehicle_user'),
            // Through the masking resource — this relation carries live M-Pesa
            // credentials, which must never reach a JSON response.
            'mpesa_payment_setting' => $this->whenLoaded(
                'mpesa_payment_setting',
                fn () => new MpesaPaymentSettingResource($this->mpesa_payment_setting),
            ),
        ];
    }
}
