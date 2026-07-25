<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use App\Enums\BookingType;

/**
 * The driver Bookings page shape (see komiut-v2 docs/driver_bookings_api_spec.md).
 *
 * Every field the app reads, named the way the app reads it: the passenger's
 * name/phone, the selected pickup/dropoff as point objects, and a bookingType
 * discriminator ("route" vs "pickAsYouGo"). passengerLatitude/Longitude carry
 * the live location for pick-as-you-go and are null for route bookings (and
 * until the passenger position channel lands — the app already defaults these).
 */
class DriverBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = $this->booking_type ?? BookingType::Route;

        return [
            'bookingId' => $this->id,
            'passengerId' => $this->user_id,
            'passengerName' => $this->name,
            'passengerPhone' => $this->phone,
            'bookingType' => $type->apiLabel(),
            'seats' => $this->whenLoaded('seats', fn () => $this->seats->pluck('seat_id')->values()),
            'amount' => $this->amount,
            'status' => $this->paid ? 'PAID' : 'RESERVED',
            'boarded' => (bool) $this->boarded,
            'pickup' => $this->point($this->whenLoaded('from') ? $this->from : null),
            'dropoff' => $this->point($this->whenLoaded('to') ? $this->to : null),
            'passengerLatitude' => null,   // pick-as-you-go only (not built yet)
            'passengerLongitude' => null,
            'createdAt' => $this->created_at,
        ];
    }

    /** A place rendered as the app's PointDto, or null. */
    private function point($place): ?array
    {
        if ($place === null) {
            return null;
        }

        return [
            'id' => $place->id,
            'name' => $place->name,
            'latitude' => $place->latitude,
            'longitude' => $place->longitude,
        ];
    }
}
