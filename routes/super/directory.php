<?php

declare(strict_types=1);

/*
| Super-admin platform console — directory claim-review workflow (PRIORITY-1).
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php,
| which requires this file once per super prefix — so declare NO named routes here.
*/

use App\Http\Controllers\APIs\Super\Directory\DirectoryController;
use Illuminate\Support\Facades\Route;

// Also open to Safetek Staff (Roles::SAFETEK_STAFF): the directory, and approving or rejecting a claim.
Route::get('directory', [DirectoryController::class, 'index'])->middleware('permission:View Platform Notifications|View Platform Tenants');
Route::post('directory/{id}/approve', [DirectoryController::class, 'approve'])->middleware('permission:View Platform Notifications|Review Directory Claims');
Route::post('directory/{id}/reject', [DirectoryController::class, 'reject'])->middleware('permission:View Platform Notifications|Review Directory Claims');

// Merging two SACCOs rewrites who owns buses and members: Super Admin only.
Route::middleware('permission:View Platform Notifications')->group(function (): void {
    Route::post('directory/{id}/merge', [DirectoryController::class, 'merge']);
});
