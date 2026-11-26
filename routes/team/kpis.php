<?php

declare(strict_types=1);

/*
| Safetek team workspace -- the ops board's KPI strip and its targets.
| Prefix (super/team) and middleware (brand, auth:sanctum, can:team-workspace)
| come from routes/api.php, which loads every routes/team/*.php file once per
| prefix -- so declare NO named routes here.
*/

use App\Http\Controllers\APIs\Super\Team\TeamKpiController;
use Illuminate\Support\Facades\Route;

Route::get('kpis', [TeamKpiController::class, 'index']);
Route::put('kpis/{key}/target', [TeamKpiController::class, 'setTarget']);
