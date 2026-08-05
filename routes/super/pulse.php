<?php

declare(strict_types=1);

/*
| Super-admin console — platform pulse / overview dashboard.
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php,
| which auto-loads every routes/super/*.php file — declare NO named routes here.
*/

use App\Http\Controllers\APIs\Super\Pulse\PulseController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:View Platform Notifications')->group(function (): void {
    Route::get('pulse', [PulseController::class, 'index']);
});
