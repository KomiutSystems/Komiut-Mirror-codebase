<?php

declare(strict_types=1);

/*
| Safetek team workspace -- members and the ops board.
| Prefix (super/team) and middleware (brand, auth:sanctum, can:team-workspace)
| come from routes/api.php, which loads every routes/team/*.php file once per
| prefix -- so declare NO named routes here.
*/

use App\Http\Controllers\APIs\Super\Team\TeamBoardController;
use Illuminate\Support\Facades\Route;

Route::get('members', [TeamBoardController::class, 'members']);
Route::get('board', [TeamBoardController::class, 'index']);
Route::post('cards', [TeamBoardController::class, 'store']);
Route::patch('cards/{card}', [TeamBoardController::class, 'update'])->whereNumber('card');
Route::post('cards/{card}/move', [TeamBoardController::class, 'move'])->whereNumber('card');
Route::delete('cards/{card}', [TeamBoardController::class, 'destroy'])->whereNumber('card');
Route::get('cards/{card}/comments', [TeamBoardController::class, 'comments'])->whereNumber('card');
Route::post('cards/{card}/comments', [TeamBoardController::class, 'addComment'])->whereNumber('card');
