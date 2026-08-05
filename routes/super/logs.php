<?php

/*
| Super-admin LOGS console — HTTP request logs, application/framework logs, and
| server (nginx / php-fpm / framework) log tails.
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php.
| Every route is additionally gated by the `View Platform Logs` permission.
*/

use App\Http\Controllers\APIs\Super\LogsController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:View Platform Logs')->group(function (): void {
    Route::get('logs/http', [LogsController::class, 'http']);
    Route::get('logs/application', [LogsController::class, 'application']);
    Route::get('logs/server', [LogsController::class, 'server']);
    Route::get('logs/sources', [LogsController::class, 'sources']);
});
