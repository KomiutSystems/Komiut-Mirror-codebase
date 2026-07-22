<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mirrors BookARideQueuesAPIController::bookSeats's inline Validator::make rules
 * verbatim (the heaviest booking-creation endpoint). Here `id` is the queue id.
 * Not yet wired into the controller.
 */
class AddBookingRequest extends FormRequest
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
            'id' => 'required|integer|min:1|exists:queues,id', // queue id
            'booking_id' => 'integer|min:1|nullable',
            'seats' => 'required|string',
            'name' => 'required|string',
            'phone' => 'required|digits_between:10,12',
            'amount' => 'required|numeric|min:0',
            'fromId' => 'integer|min:0|nullable',
            'toId' => 'integer|min:0|nullable',
        ];
    }
}
