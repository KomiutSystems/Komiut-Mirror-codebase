<?php

declare(strict_types=1);

namespace App\Providers\Super;

use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Models\MpesaPaymentSetting;
use App\Models\Vehicle;
use App\Observers\Super\Money\LoyaltyProgramObserver;
use App\Observers\Super\Money\LoyaltyRedemptionObserver;
use App\Observers\Super\Money\MpesaPaymentSettingObserver;
use App\Observers\Super\Money\VehiclePaymentObserver;
use Illuminate\Support\ServiceProvider;

/**
 * Super-admin MONEY-INTEGRITY console wiring.
 *
 * Observer-driven events:
 *   1 vehicle.payment_details.changed + 2 vehicle.till.duplicate  → VehiclePaymentObserver (Vehicle)
 *   1 vehicle.payment_details.changed (SACCO credentials)         → MpesaPaymentSettingObserver
 *   5 loyalty.program.extreme_config                              → LoyaltyProgramObserver
 *   6 loyalty.redemption.spike                                    → LoyaltyRedemptionObserver
 *
 * Call-site-driven events (no observer — a real trigger lives in code):
 *   3 payment.reconciliation.failed  → MpesaPaymentsController, NCBARestPaymentsController,
 *                                       ReconcileMpesaPayments (via PaymentReconciliationAlerter)
 *   4 loyalty.earn.failure_rate_high → EarnLoyaltyPoints catch (via LoyaltyEarnFailureTracker)
 */
class MoneyEventsProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Vehicle::observe(VehiclePaymentObserver::class);
        MpesaPaymentSetting::observe(MpesaPaymentSettingObserver::class);
        LoyaltyProgram::observe(LoyaltyProgramObserver::class);
        LoyaltyTransaction::observe(LoyaltyRedemptionObserver::class);
    }
}
