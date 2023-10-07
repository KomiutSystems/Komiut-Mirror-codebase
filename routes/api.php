<?php

use App\Http\Controllers\APIs\AuthController;
use App\Http\Controllers\APIs\Dashboard\BookARide\BookARideQueuesAPIController;
use App\Http\Controllers\APIs\Dashboard\BookARide\BookARideRoutesAPIController;
use App\Http\Controllers\APIs\Dashboard\BookARide\BookARideSaccoRoutesAPIController;
use App\Http\Controllers\APIs\Dashboard\BookARide\BookARideSeatController;
use App\Http\Controllers\APIs\Dashboard\Bookings\BookingsAPIController;
use App\Http\Controllers\APIs\Dashboard\HomeAPIController;
use App\Http\Controllers\APIs\Dashboard\Profiles\ProfileAPIController;
use App\Http\Controllers\APIs\Dashboard\Queues\QueuesAPIController;
use App\Http\Controllers\APIs\Dashboard\Queues\QueueStatusAPIController;
use App\Http\Controllers\APIs\Dashboard\Routes\PlaceAPIController;
use App\Http\Controllers\APIs\Dashboard\Routes\RouteAPIController;
use App\Http\Controllers\APIs\Dashboard\Routes\TerminusAPIController;
use App\Http\Controllers\APIs\Dashboard\Saccos\SaccoAPIController;
use App\Http\Controllers\APIs\Dashboard\Saccos\SaccoMembersAPIController;
use App\Http\Controllers\APIs\Dashboard\Saccos\SaccoRoutesAPIController;
use App\Http\Controllers\APIs\Dashboard\Saccos\SaccoVehiclesAPIController;
use App\Http\Controllers\APIs\Dashboard\Settings\GenderAPIController;
use App\Http\Controllers\APIs\Dashboard\Transactions\CashAPIController;
use App\Http\Controllers\APIs\Dashboard\Transactions\MpesaAPIController;
use App\Http\Controllers\APIs\Dashboard\Transactions\TransactionsAPIController;
use App\Http\Controllers\APIs\Dashboard\Users\RoleAPIController;
use App\Http\Controllers\APIs\Dashboard\Users\UsersAPIController;
use App\Http\Controllers\APIs\Dashboard\Vehicles\SeatsAPIController;
use App\Http\Controllers\APIs\Dashboard\Vehicles\VehiclesAPIController;
use App\Http\Controllers\APIs\Dashboard\Vehicles\VehicleUsersAPIController;
use App\Http\Controllers\APIs\IndexApiController;
use App\Http\Controllers\APIs\MpesaPaymentsController;
use App\Http\Controllers\Services\SendFCMMessageController;
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
Route::group(['middleware'=>['api']], function($router){
    Route::any('stk/push/response', [MpesaPaymentsController::class, 'stkResponse']);
    Route::any('fcm/notification/test', [SendFCMMessageController::class, 'sendTestNotification']);
    Route::any('payments/notifications/test', [MpesaPaymentsController::class, 'paymentsNotification']);
});

Route::group([

    'middleware' => ['api'],
    'prefix' => 'auth'

], function ($router) {
    Route::any('mpesa/stk', [MpesaPaymentsController::class, 'customerMpesaSTKPush']);
    Route::get('genders', [IndexApiController::class, 'getGenders']);
    //Auth
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::get('refresh', [AuthController::class, 'refresh']);
    //dashboard controller
    Route::get('dashboard', [HomeAPIController::class, 'getDashboard']);
    //Book a ride
    Route::get('book_a_ride/routes', [BookARideRoutesAPIController::class, 'getRoutes']);
    Route::get('book_a_ride/route_saccos', [BookARideSaccoRoutesAPIController::class, 'getSaccoRoutes']);
    Route::get('book_a_ride/queues', [BookARideQueuesAPIController::class, 'getQueues']);
    Route::get('book_a_ride/seats', [BookARideSeatController::class, 'getVehicleSeats']);
    Route::post('book_a_ride/booking/add', [BookARideQueuesAPIController::class, 'addBooking']);
    //Transactions
    Route::get('transactions', [TransactionsAPIController::class, 'getTransactions']);
    Route::get('transactions/mpesa', [MpesaAPIController::class, 'getTransactions']);
    Route::get('transactions/cash', [CashAPIController::class, 'getTransactions']);
    //routes
    Route::get('routes/places', [PlaceAPIController::class, 'getPlaces']);
    Route::get('routes', [RouteAPIController::class, 'getRoutes']);
    Route::get('routes/places/{id}', [RouteAPIController::class, 'getRoutePlaces']);
    Route::get('routes/termini', [TerminusAPIController::class, 'getTermini']);
    //Queues
    Route::get('queues', [QueuesAPIController::class, 'getQueues']);
    Route::post('queues/add', [QueuesAPIController::class, 'addQueue']);
    Route::get('queues/view/{id}', [QueuesAPIController::class, 'getQueue']);
    Route::get('queues/statuses', [QueueStatusAPIController::class, 'getQueueStatuses']);
    Route::post('queues/statuses/add', [QueueStatusAPIController::class, 'addQueueStatus']);
    //Saccos
    Route::get('saccos', [SaccoAPIController::class, 'getSaccos']);
    Route::get('saccos/members', [SaccoMembersAPIController::class, 'getMembers']);
    Route::get('saccos/vehicles', [SaccoVehiclesAPIController::class, 'getSaccoVehicles']);
    Route::get('saccos/routes', [SaccoRoutesAPIController::class, 'getSaccoRoutes']);
    //
    //Vehicles
    Route::get('vehicles', [VehiclesAPIController::class, 'getVehicles']);
    Route::get('vehicles/users', [VehicleUsersAPIController::class, 'getVehicleUsers']);
    Route::get('vehicles/seat_settings', [SeatsAPIController::class, 'getSeats']);

    //Bookings
    Route::get('bookings/passengers', [BookingsAPIController::class, 'getPassengerBookings']);
    Route::get('bookings/passengers/view/{id}', [BookingsAPIController::class, 'getPassengerBooking']);
    Route::get('bookings/parcels', [BookingsAPIController::class, 'getParcels']);
    
    //users
    Route::get('users', [UsersAPIController::class, 'getUsers']);
    Route::get('users/roles', [RoleAPIController::class, 'getRoles']);

    //settings
    Route::get('settings/gender', [GenderAPIController::class, 'getGenders']);

    //profile
    Route::post('profile/edit', [ProfileAPIController::class, 'editProfile']);
    Route::post('profile/change_password', [ProfileAPIController::class, 'changePassword']);
    Route::post('profile/upload_picture', [ProfileAPIController::class, 'uploadProfilePicture']);

    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::post('user', [AuthController::class, 'user']);
});
