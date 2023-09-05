<?php

use App\Http\Controllers\APIs\AuthController;
use App\Http\Controllers\APIs\Dashboard\Bookings\BookingsAPIController;
use App\Http\Controllers\APIs\Dashboard\HomeAPIController;
use App\Http\Controllers\APIs\Dashboard\Queues\QueuesAPIController;
use App\Http\Controllers\APIs\Dashboard\Queues\QueueStatusAPIController;
use App\Http\Controllers\APIs\Dashboard\Routes\PlaceAPIController;
use App\Http\Controllers\APIs\Dashboard\Routes\RouteAPIController;
use App\Http\Controllers\APIs\Dashboard\Routes\TerminusAPIController;
use App\Http\Controllers\APIs\Dashboard\Saccos\SaccoAPIController;
use App\Http\Controllers\APIs\Dashboard\Saccos\SaccoMembersAPIController;
use App\Http\Controllers\APIs\Dashboard\Saccos\SaccoRoutesAPIController;
use App\Http\Controllers\APIs\Dashboard\Saccos\SaccoVehiclesAPIController;
use App\Http\Controllers\APIs\Dashboard\Transactions\CashAPIController;
use App\Http\Controllers\APIs\Dashboard\Transactions\MpesaAPIController;
use App\Http\Controllers\APIs\Dashboard\Transactions\TransactionsAPIController;
use App\Http\Controllers\APIs\Dashboard\Vehicles\SeatsAPIController;
use App\Http\Controllers\APIs\Dashboard\Vehicles\VehiclesAPIController;
use App\Http\Controllers\APIs\Dashboard\Vehicles\VehicleUsersAPIController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/
/*
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});*/
Route::group([

    'middleware' => ['api'],
    'prefix' => 'auth'

], function ($router) {
    //Auth
    Route::post('login', [AuthController::class, 'login']);
    //dashboard controller
    Route::get('dashboard', [HomeAPIController::class, 'getDashboard']);
    //Transactions
    Route::get('transactions', [TransactionsAPIController::class, 'getTransactions']);
    Route::get('transactions/mpesa', [MpesaAPIController::class, 'getTransactions']);
    Route::get('transactions/cash', [CashAPIController::class, 'getTransactions']);
    //routes
    Route::get('routes/places', [PlaceAPIController::class, 'getPlaces']);
    Route::get('routes', [RouteAPIController::class, 'getRoutes']);
    Route::get('routes/termini', [TerminusAPIController::class, 'getTermini']);
    //Queues
    Route::get('queues', [QueuesAPIController::class, 'getQueues']);
    Route::get('queues/statuses', [QueueStatusAPIController::class, 'getQueueStatuses']);
    //Saccos
    Route::get('saccos', [SaccoAPIController::class, 'getSaccos']);
    Route::get('saccos/members', [SaccoMembersAPIController::class, 'getMembers']);
    Route::get('saccos/vehicles', [SaccoVehiclesAPIController::class, 'getSaccoVehicles']);
    Route::get('saccos/routes', [SaccoRoutesAPIController::class, 'getSaccoRoutes']);
    //Vehicles
    Route::get('vehicles', [VehiclesAPIController::class, 'getVehicles']);
    Route::get('vehicles/users', [VehicleUsersAPIController::class, 'getVehicleUsers']);
    Route::get('vehicles/seat_settings', [SeatsAPIController::class, 'getSeats']);

    //Bookings
    Route::get('bookings/passengers', [BookingsAPIController::class, 'getPassengerBookings']);

    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::post('user', [AuthController::class, 'user']);
});
