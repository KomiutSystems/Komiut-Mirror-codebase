<?php

declare(strict_types=1);

/*
| Super-admin console — RBAC reads + the role→permissions mutation.
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php,
| which auto-loads every routes/super/*.php file — declare NO named routes here.
*/

use App\Http\Controllers\APIs\Super\Rbac\RbacController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:View Platform Notifications')->group(function (): void {
    Route::get('brands', [RbacController::class, 'brands']);
    Route::get('roles', [RbacController::class, 'roles']);
    Route::get('permissions', [RbacController::class, 'permissions']);
    Route::put('roles/{role}/permissions', [RbacController::class, 'updatePermissions']);
});
