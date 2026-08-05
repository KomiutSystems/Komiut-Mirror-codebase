<?php

/*
| Super-admin console — ACCESS/PRIVILEGE domain read routes.
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php,
| which requires this file once per super prefix — so declare NO named routes here.
*/

use App\Http\Controllers\APIs\Super\Access\AccessAuditController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:View Platform Notifications')->group(function (): void {
    Route::get('access/audit', [AccessAuditController::class, 'index']);
});
