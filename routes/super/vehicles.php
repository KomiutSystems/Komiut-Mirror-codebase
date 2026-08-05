<?php

declare(strict_types=1);

/*
| Super-admin platform console — cross-brand fleet read.
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php,
| which auto-loads every routes/super/*.php file.
*/

use App\Http\Controllers\APIs\Super\Vehicles\VehiclesController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:View Platform Notifications')->group(function (): void {
    Route::get('vehicles', [VehiclesController::class, 'index']);
});
