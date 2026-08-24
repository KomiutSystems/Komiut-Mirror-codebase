<?php

declare(strict_types=1);

/*
| Super-admin platform console — sacco_termini attach / detach (the only writer).
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php,
| which requires this file once per super prefix — so declare NO named routes here.
|
| A SEPARATE file from routes/super/routes-termini.php on purpose. That file wraps
| its reads in `permission:View Platform Notifications`, and nested route middleware
| STACKS — declaring these inside it would demand that unrelated console permission
| on top of the termini ones. These use the 'Termini Saccos' permissions, which are
| the ones that actually name this link (and which Operations Manager now holds).
*/

use App\Http\Controllers\APIs\Super\Termini\SaccoTerminiController;
use Illuminate\Support\Facades\Route;

Route::get('saccos/{id}/termini', [SaccoTerminiController::class, 'index'])
    ->middleware('permission:View Termini Saccos')
    ->whereNumber('id');

// Attach is an upsert — it creates a link or re-sets the radius on an existing
// one — so either half of the add/edit pair is an honest gate for it.
Route::post('saccos/{id}/termini', [SaccoTerminiController::class, 'attach'])
    ->middleware('permission:Add Termini Saccos|Edit Termini Saccos')
    ->whereNumber('id');

Route::delete('saccos/{id}/termini/{terminus}', [SaccoTerminiController::class, 'detach'])
    ->middleware('permission:Edit Termini Saccos')
    ->whereNumber(['id', 'terminus']);
