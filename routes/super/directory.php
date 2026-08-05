<?php

declare(strict_types=1);

/*
| Super-admin platform console — directory claim-review workflow (PRIORITY-1).
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php,
| which requires this file once per super prefix — so declare NO named routes here.
*/

use App\Http\Controllers\APIs\Super\Directory\DirectoryController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:View Platform Notifications')->group(function (): void {
    Route::get('directory', [DirectoryController::class, 'index']);
    Route::post('directory/{id}/merge', [DirectoryController::class, 'merge']);
    Route::post('directory/{id}/approve', [DirectoryController::class, 'approve']);
    Route::post('directory/{id}/reject', [DirectoryController::class, 'reject']);
});
