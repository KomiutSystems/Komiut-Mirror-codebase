<?php

/*
| Super-admin money-integrity domain routes.
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php,
| which auto-loads every routes/super/*.php file.
*/

use App\Http\Controllers\APIs\Super\Money\MoneyLogController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:View Platform Notifications')->group(function (): void {
    // The money audit trail: payment-detail changes, reconciliation, loyalty config.
    Route::get('money/logs', [MoneyLogController::class, 'index']);
});
