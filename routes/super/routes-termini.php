<?php

declare(strict_types=1);

/*
| Super-admin platform console — routes + termini reads (cross-brand).
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php,
| which auto-loads every routes/super/*.php file.
*/

use App\Http\Controllers\APIs\Super\RoutesTermini\RoutesTerminiController;
use Illuminate\Support\Facades\Route;

// Also open to Safetek Staff (Roles::SAFETEK_STAFF): read-only.
Route::middleware('permission:View Platform Notifications|View Platform Tenants')->group(function (): void {
    Route::get('routes', [RoutesTerminiController::class, 'routes']);
    Route::get('termini', [RoutesTerminiController::class, 'termini']);
});
