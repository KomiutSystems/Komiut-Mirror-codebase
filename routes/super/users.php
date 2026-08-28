<?php

declare(strict_types=1);

/*
| Super-admin console — user directory + admin actions on an account.
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php,
| which auto-loads every routes/super/*.php file — declare NO named routes here.
*/

use App\Http\Controllers\APIs\Super\Users\BankAccessController;
use App\Http\Controllers\APIs\Super\Users\UserActionsController;
use App\Http\Controllers\APIs\Super\Users\UsersController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:View Platform Notifications')->group(function (): void {
    Route::get('users', [UsersController::class, 'index']);
    Route::post('users/{user}/suspend', [UserActionsController::class, 'suspend']);
    Route::post('users/{user}/restore', [UserActionsController::class, 'restore']);
    Route::post('users/{user}/password-reset', [UserActionsController::class, 'passwordReset']);
    Route::delete('users/{user}', [UserActionsController::class, 'destroy']);

    // The only write path for `users.financier`. It lives under /super — and so
    // behind EnsureSuperAdmin — because granting it crosses SACCO boundaries by
    // design: Roles::saccoAssignable() deliberately omits Bank Viewer so that no
    // SACCO admin can mint one, and routing this anywhere a SACCO admin can
    // reach would hand back the escalation path that omission closes.
    Route::post('users/{user}/bank-access', [BankAccessController::class, 'provision']);
});
