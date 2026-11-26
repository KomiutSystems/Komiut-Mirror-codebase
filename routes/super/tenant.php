<?php

/*
| Super-admin platform console — tenant lifecycle (SACCO review + audit).
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php,
| which auto-loads every routes/super/*.php file. Controller classes are used
| (not closures) so `route:cache` in the prod entrypoint keeps working.
*/

use App\Http\Controllers\APIs\Super\TenantController;
use Illuminate\Support\Facades\Route;

// Also open to Safetek Staff (Roles::SAFETEK_STAFF): the review queue, read-only.
// The pending-SACCO + duplicate-suspect queue (open review-class events).
Route::get('tenant/review-queue', [TenantController::class, 'reviewQueue'])->middleware('permission:View Platform Notifications|View Platform Tenants');

Route::middleware('permission:View Platform Notifications')->group(function (): void {
    // The append-only sacco.* audit trail.
    Route::get('tenant/audit', [TenantController::class, 'audit']);
});
