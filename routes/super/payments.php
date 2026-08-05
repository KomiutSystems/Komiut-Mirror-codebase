<?php

declare(strict_types=1);

/*
| Super-admin console — cross-brand PAYMENT read aggregates (PRIORITY-1).
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php,
| which auto-loads every routes/super/*.php file — so declare NO named routes
| here (this file is required once per super prefix).
|
| These are live/aggregate payment reads, distinct from money/logs (the audit
| trail) already registered in routes/super/money.php.
*/

use App\Http\Controllers\APIs\Super\Payments\PaymentsController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:View Platform Notifications')->group(function (): void {
    Route::get('payments', [PaymentsController::class, 'index']);
    Route::get('payments/summary', [PaymentsController::class, 'summary']);
});
