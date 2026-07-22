<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract-capture of the current User JSON shape.
 *
 * Keys derived from the users table migration (2014_10_12_000000) minus the
 * model's $hidden (password, remember_token), plus id + timestamps.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'email' => $this->email,
            'phone' => $this->phone,
            'email_verified_at' => $this->email_verified_at,
            'dob' => $this->dob,
            'gender_id' => $this->gender_id,
            'sacco_id' => $this->sacco_id,
            'type' => $this->type,
            'image' => $this->image,
            'status' => $this->status,
            // `provider` / `provider_id` are intentionally omitted — internal
            // social-link identifiers, not part of the public user contract.
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Relations (faithful to whatever the controller eager-loaded).
            'gender' => $this->whenLoaded('gender'),
            'sacco' => $this->whenLoaded('sacco'),
            'roles' => $this->whenLoaded('roles'),
            'vehicle_users' => $this->whenLoaded('vehicle_users'),
        ];
    }
}
