<?php

declare(strict_types=1);

/*
| Safetek team workspace -- team performance (the console's /super/kpis page).
| Prefix and middleware come from the team loader in routes/api.php.
*/

use App\Http\Controllers\APIs\Super\Team\TeamPerformanceController;
use Illuminate\Support\Facades\Route;

Route::get('performance', TeamPerformanceController::class);
