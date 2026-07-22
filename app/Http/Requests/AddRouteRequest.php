<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mirrors RouteAPIController::addRoute's inline Validator::make rules verbatim.
 * The permission gate (Add/Edit Routes) stays in the controller. Not yet wired.
 */
class AddRouteRequest extends FormRequest
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
            'name' => 'nullable|string',
            'from' => 'required|string',
            'to' => 'required|string|different:from',
            'status' => 'required|min:0|max:1',
        ];
    }
}
