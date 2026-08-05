<?php

/*
| Super-admin platform console — tenant lifecycle (SACCO review + audit).
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php,
| which auto-loads every routes/super/*.php file. Controller classes are used
| (not closures) so `route:cache` in the prod entrypoint keeps working.
*/

use App\Http\Controllers\APIs\Super\TenantController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:View Platform Notifications')->group(function (): void {
    // The pending-SACCO + duplicate-suspect queue (open review-class events).
    Route::get('tenant/review-queue', [TenantController::class, 'reviewQueue']);
    // The append-only sacco.* audit trail.
    Route::get('tenant/audit', [TenantController::class, 'audit']);
});
