<?php

/*
| Super-admin console — alert THRESHOLD settings (read + retune).
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php,
| which requires this file once per super prefix — so declare NO named routes here.
*/

use App\Http\Controllers\APIs\Super\Platform\ThresholdsController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:View Platform Notifications')->group(function (): void {
    Route::get('thresholds', [ThresholdsController::class, 'index']);
    Route::put('thresholds', [ThresholdsController::class, 'update']);
});
