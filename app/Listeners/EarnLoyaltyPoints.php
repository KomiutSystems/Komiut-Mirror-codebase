<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\BookingPaid;
use App\Services\Loyalty\LoyaltyService;

class EarnLoyaltyPoints
{
    public function __construct(private LoyaltyService $loyalty) {}

    public function handle(BookingPaid $event): void
    {
        $this->loyalty->earnForBooking($event->booking);
    }
}
