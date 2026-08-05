<?php

declare(strict_types=1);

/*
| Super-admin platform console — generic reference-data CRUD.
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php,
| which auto-loads every routes/super/*.php file.
|
| {set} is validated in the controller against a fixed config map (genders,
| seat_layouts, queue_statuses, expense_types, places, termini) — never used to
| reach an arbitrary model, so no route-level constraint here (that would 404
| an unknown set instead of the required 422 "Unknown reference set").
| No DELETE route: these models are never hard-deleted (status=false via PATCH).
*/

use App\Http\Controllers\APIs\Super\Reference\ReferenceController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:View Platform Notifications')->group(function (): void {
    Route::get('reference/{set}', [ReferenceController::class, 'index']);
    Route::post('reference/{set}', [ReferenceController::class, 'store']);
    Route::patch('reference/{set}/{id}', [ReferenceController::class, 'update']);
});
