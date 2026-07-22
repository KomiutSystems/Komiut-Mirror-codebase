<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mirrors VehiclesAPIController::addVehicle's inline Validator::make rules
 * verbatim. The permission gate (Add/Edit Vehicles) stays in the controller
 * and will be reconciled during the wiring pass. Not yet wired.
 */
class AddVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id' => 'required|min:0|integer',
            // Dynamic unique-ignore preserved from the controller: excludes the
            // row being edited (id) so an update keeps its own plate.
            'plate' => 'required|string|unique:vehicles,plate,' . $this->id,
            'fleet_no' => 'string|nullable',
            'till_number' => 'integer|nullable',
            'sacco' => 'string|nullable',
            'seat' => 'required|exists:seats,name',
            'merchant_short_code' => 'integer|nullable',
            'status' => 'required|min:0|integer',
        ];
    }
}
