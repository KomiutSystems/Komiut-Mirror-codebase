<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Financier;
use App\Models\Scopes\FinancierScope;
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
        return $this->forViewer($request, $this->fields());
    }

    /**
     * What a BANK is shown. Every bus on its screen is one it finances
     * (FinancierScope), so `financier` tells it nothing -- except, by being a
     * column at all, that other banks exist. Nor is it shown the other bank's
     * collection-account column, whose very name says the same thing. The
     * SACCO each bus belongs to stays: a bank reads its fleet SACCO by SACCO.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function forViewer(Request $request, array $fields): array
    {
        $viewer = $request->user();
        if (! FinancierScope::confines($viewer)) {
            return $fields;
        }

        unset($fields['financier'], $fields['till_registration_status'], $fields['till_registration_note'], $fields['till_registration_attempted_at']);
        $own = $viewer->currentFinancier();
        if ($own !== Financier::Ncba) {
            unset($fields['ncba_till']);
        }
        if ($own !== Financier::Coop) {
            unset($fields['coop_till']);
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function fields(): array
    {
        return [
            'id' => $this->id,
            'plate' => $this->plate,
            'fleet_no' => $this->fleet_no,
            'till_number' => $this->till_number,
            'merchant_short_code' => $this->merchant_short_code,
            'ncba_till' => $this->ncba_till,
            'coop_till' => $this->coop_till,
            // Which bank finances it (NULL = none) and which portal shows it.
            // The dashboard's Bank column read `financier` and, with the field
            // absent here, labelled every bus "Unfinanced" -- on the bank's
            // own screen.
            'financier' => $this->financier,
            'brand' => $this->brand,
            'sacco_id' => $this->sacco_id,
            'user_id' => $this->user_id,
            'seat_id' => $this->seat_id,
            'mpesa_payment_setting_id' => $this->mpesa_payment_setting_id,
            // Where the bus's till stands with Safaricom (App\Services\Mpesa\
            // TillRegistrar::MESSAGES names each status). In the list, not only
            // on the save response, so a bus saved before the registrar
            // existed shows its state too.
            'till_registration_status' => $this->till_registration_status,
            'till_registration_note' => $this->till_registration_note,
            'till_registration_attempted_at' => $this->till_registration_attempted_at === null ? null
                : \Illuminate\Support\Carbon::parse($this->till_registration_attempted_at, 'UTC')->toIso8601String(),
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
