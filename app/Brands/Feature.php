<?php

declare(strict_types=1);

namespace App\Brands;

/**
 * Feature flags a brand may switch on or off.
 *
 * The backing value is the key used inside the `features` array of
 * config/brands.php — the two must stay in lockstep.
 */
enum Feature: string
{
    case Parcels = 'parcels';
    case Carpool = 'carpool';
    case Wallet = 'wallet';
    case Bookings = 'bookings';
    case Loyalty = 'loyalty';
}
