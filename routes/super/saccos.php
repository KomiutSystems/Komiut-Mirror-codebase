<?php

declare(strict_types=1);

/*
| Super-admin platform console — SACCO list + detail (PRIORITY-1).
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php,
| which requires this file once per super prefix — so declare NO named routes here.
*/

use App\Http\Controllers\APIs\Super\Saccos\SaccoDetailController;
use App\Http\Controllers\APIs\Super\Saccos\SaccosController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:View Platform Notifications')->group(function (): void {
    Route::get('saccos', [SaccosController::class, 'index']);
    Route::get('saccos/{id}', [SaccoDetailController::class, 'show']);
});
