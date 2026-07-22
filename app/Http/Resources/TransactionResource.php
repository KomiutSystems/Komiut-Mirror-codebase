<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract-capture of the current Transaction JSON shape.
 *
 * Keys derived from the transactions table migration (2023_07_12_092001) plus
 * the is_synced alter (2024_09_19), id + timestamps.
 */
class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'cash_id' => $this->cash_id,
            'mpesa_id' => $this->mpesa_id,
            'amount' => $this->amount,
            'points' => $this->points,
            'trans_date' => $this->trans_date,
            'redeemed' => $this->redeemed,
            'summarized' => $this->summarized,
            'is_synced' => $this->is_synced,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'cash' => $this->whenLoaded('cash'),
            'mpesa' => $this->whenLoaded('mpesa'),
            'vehicle' => $this->whenLoaded('vehicle'),
            'direct_line_claim' => $this->whenLoaded('direct_line_claim'),
        ];
    }
}
