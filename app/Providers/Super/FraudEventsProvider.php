<?php

declare(strict_types=1);

namespace App\Providers\Super;

use App\Models\User;
use App\Models\Vehicle;
use App\Observers\Super\Fraud\CrossSaccoPlateObserver;
use App\Observers\Super\Fraud\DuplicateIdNumberObserver;
use Illuminate\Support\ServiceProvider;

/**
 * Fraud / abuse / data-protection domain of the super-admin console.
 *
 * Most of this domain's signals are emitted inline from the controllers,
 * middleware and services that own the trigger (driver login, vehicle assignment,
 * onboarding, the bank portal). The two signals that have no single call site —
 * a duplicate national ID and a plate claimed by two saccos — are model-state
 * facts, so they are observed here.
 *
 * NOTE (driver.erasure.requested — GDPR-style erasure): no driver-erasure endpoint
 * exists in the codebase today, so there is no real trigger to hook. TODO: emit
 * `driver.erasure.requested` (review/high) from that endpoint once it ships.
 */
class FraudEventsProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        User::observe(DuplicateIdNumberObserver::class);
        Vehicle::observe(CrossSaccoPlateObserver::class);
    }
}
