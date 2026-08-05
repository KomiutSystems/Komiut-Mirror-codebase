<?php

declare(strict_types=1);

/*
| Super-admin console — tills overview (till/merchant grouping + conflict
| detection) and the payment-details-changed audit projection.
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php,
| which auto-loads every routes/super/*.php file — so declare NO named routes
| here (this file is required once per super prefix).
*/

use App\Http\Controllers\APIs\Super\Payments\TillsOverviewController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:View Platform Notifications')->group(function (): void {
    Route::get('tills', [TillsOverviewController::class, 'index']);
    Route::get('tills/changes', [TillsOverviewController::class, 'changes']);
});
