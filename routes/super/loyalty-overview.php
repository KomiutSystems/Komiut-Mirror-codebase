<?php

declare(strict_types=1);

/*
| Super-admin console — cross-brand loyalty program overview.
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php,
| which auto-loads every routes/super/*.php file — so declare NO named routes
| here (this file is required once per super prefix).
*/

use App\Http\Controllers\APIs\Dashboard\Loyalty\LoyaltyHoldersController;
use App\Http\Controllers\APIs\Super\Payments\LoyaltyOverviewController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:View Platform Notifications')->group(function (): void {
    Route::get('loyalty', [LoyaltyOverviewController::class, 'index']);

    // Every point holder on the platform, one row per PERSON with their
    // per-SACCO split nested inside. Read only — see the controller docblock.
    Route::get('loyalty/holders', [LoyaltyHoldersController::class, 'forPlatform']);
});
