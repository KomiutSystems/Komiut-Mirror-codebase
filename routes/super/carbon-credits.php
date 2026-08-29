<?php

declare(strict_types=1);

/*
| Super-admin console — the platform's carbon credit scheme.
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php.
| Declare NO named routes here.
*/

use App\Http\Controllers\APIs\Super\CarbonCredits\CarbonCreditAdminController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:View Platform Notifications')->group(function (): void {
    Route::get('carbon-credits', [CarbonCreditAdminController::class, 'overview']);
    Route::get('carbon-credits/rewards', [CarbonCreditAdminController::class, 'rewards']);
    Route::post('carbon-credits/rewards/save', [CarbonCreditAdminController::class, 'saveReward']);
    Route::get('carbon-credits/redemptions', [CarbonCreditAdminController::class, 'redemptions']);
    Route::post('carbon-credits/redemptions/settle', [CarbonCreditAdminController::class, 'settleRedemption']);
});
