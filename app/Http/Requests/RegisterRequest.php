<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mirrors AuthController::register's inline Validator::make rules verbatim.
 * Not yet wired into the controller (wiring happens in a later pass).
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Registration is public (auth middleware excludes it); gate stays open.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'firstname' => 'required|string',
            'lastname' => 'required|string',
            'email' => 'required|email|unique:users',
            'phone' => 'required|digits:10|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'dob' => 'date|before:today',
            'gender' => 'required|exists:genders,name',
        ];
    }
}
