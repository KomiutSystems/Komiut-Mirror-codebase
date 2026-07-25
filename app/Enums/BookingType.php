<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a booking was made, which decides whether the driver app shows a map.
 *
 *  - Route: booked while the matatu waits at the terminus — a fixed
 *    pickup/dropoff along the route, no live passenger location needed.
 *  - PickAsYouGo: booked while the matatu is already on the road broadcasting
 *    its position; the passenger flags it down from wherever they are, so the
 *    driver needs their live location to reach them.
 *
 * The backing value is what is stored on `bookings.booking_type`. The mobile
 * contract (komiut-v2) reads the camelCase form — see apiLabel().
 */
enum BookingType: string
{
    case Route = 'route';
    case PickAsYouGo = 'pick_as_you_go';

    /**
     * The mode a booking takes when placed against a queue in this state: a
     * matatu that has departed (Active) is picking up along the way; otherwise
     * it is still filling at the terminus.
     */
    public static function forQueueStatus(?string $status): self
    {
        return $status === 'Active' ? self::PickAsYouGo : self::Route;
    }

    /** The discriminator string the mobile app expects. */
    public function apiLabel(): string
    {
        return match ($this) {
            self::Route => 'route',
            self::PickAsYouGo => 'pickAsYouGo',
        };
    }
}
