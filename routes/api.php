<?php

use App\Http\Controllers\APIs\AuthController;
use App\Http\Controllers\APIs\CoopRestPaymentsController;
use App\Http\Controllers\APIs\Dashboard\BookARide\BookARideQueuesAPIController;
use App\Http\Controllers\APIs\Dashboard\BookARide\BookARideRoutesAPIController;
use App\Http\Controllers\APIs\Dashboard\BookARide\BookARideSaccoRoutesAPIController;
use App\Http\Controllers\APIs\Dashboard\BookARide\BookARideSeatController;
use App\Http\Controllers\APIs\Dashboard\Bookings\BookingsAPIController;
use App\Http\Controllers\APIs\Dashboard\HomeAPIController;
use App\Http\Controllers\APIs\Dashboard\Points\PointsAPIController;
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
use App\Http\Controllers\APIs\NCBARestPaymentsController;
use App\Http\Controllers\APIs\NCBASoapPaymentsController;
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
    Route::any('mpesas/copy', [IndexApiController::class, 'copyMpesaTransactions']);
    Route::any('cashes/copy', [IndexApiController::class, 'copyCashTransactions']);
    Route::any('saccos/copy', [IndexApiController::class, 'copySaccos']);

    Route::any('queues/copy', [IndexApiController::class, 'copyQueues']);
    Route::any('queues/copy/from', [IndexApiController::class, 'copyQueuesFrom']);

    Route::any('vehicle_users/copy', [IndexApiController::class, 'copyVehicleUsers']);
    Route::any('vehicle_users/copy/from', [IndexApiController::class, 'copyVehicleUsersFrom']);

    Route::any('sacco_routes/copy', [IndexApiController::class, 'copySaccoRoutes']);
    Route::any('sacco_routes/copy/from', [IndexApiController::class, 'copySaccoRoutesFrom']);

    Route::any('route_stages/copy', [IndexApiController::class, 'copyRouteStages']);
    Route::any('route_stages/copy/from', [IndexApiController::class, 'copyRouteStagesFrom']);

    Route::any('queue_statuses/copy', [IndexApiController::class, 'copyQueueStatuses']);
    Route::any('queue_statuses/copy/from', [IndexApiController::class, 'copyQueueStatusesFrom']);

    Route::any('saccos/termini/copy', [IndexApiController::class, 'copySaccoTermini']);
    Route::any('saccos/termini/copy/from', [IndexApiController::class, 'copySaccoTerminiFrom']);

    Route::any('termini/copy', [IndexApiController::class, 'copyTermini']);
    Route::any('termini/copy/from', [IndexApiController::class, 'copyTerminiFrom']);

    Route::any('routes/copy', [IndexApiController::class, 'copyRoutes']);
    Route::any('routes/copy/from', [IndexApiController::class, 'copyRoutesFrom']);

    Route::any('places/copy', [IndexApiController::class, 'copyPlaces']);
    Route::any('places/copy/from', [IndexApiController::class, 'copyPlacesFrom']);

    Route::any('saccos/copy', [IndexApiController::class, 'copySaccos']);
    Route::any('saccos/copy/from', [IndexApiController::class, 'copySaccosFrom']);

    Route::any('vehicles/copy', [IndexApiController::class, 'copyVehicles']);
    Route::any('vehicles/copy/from', [IndexApiController::class, 'copyVehiclesFrom']);

    Route::any('seats/copy', [IndexApiController::class, 'copySeats']);
    Route::any('seats/copy/from', [IndexApiController::class, 'copySeatsFrom']);

    Route::any('users/passwords/copy', [IndexApiController::class, 'copyUserPasswords']);
    Route::any('users/passwords/copy/from', [IndexApiController::class, 'copyUserPasswordsFrom']);

    Route::any('users/copy', [IndexApiController::class, 'copyUsers']);
    Route::any('users/copy/from', [IndexApiController::class, 'copyUsersFrom']);
    Route::any('roles/copy', [IndexApiController::class, 'copyRoles']);
    Route::any('roles/copy/from', [IndexApiController::class, 'copyRolesFrom']);

    //NCBA Endpoints
    Route::any('mpesa/confirmation', [NCBASoapPaymentsController::class, 'mpesaPayments']);
    Route::any('rest/mpesa/confirmation', [NCBARestPaymentsController::class, 'restMpesaPayments']);
    Route::any('mpesa/confirmation_new', [NCBARestPaymentsController::class, 'mpesaNewPayments']);
    Route::any('rest/mpesa/confirmation_new', [NCBARestPaymentsController::class, 'restMpesaNewPayments']);

    //Coop Endpoints
    Route::any('coop/mpesa', [CoopRestPaymentsController::class, 'coopMpesaPayments']);

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
    Route::post('routes/place/add', [PlaceAPIController::class, 'addPlace']);
    Route::get('routes/place/view/{id}', [PlaceAPIController::class, 'getPlace']);
    Route::get('routes', [RouteAPIController::class, 'getRoutes']);
    Route::post('routes/add', [RouteAPIController::class, 'addRoute']);
    Route::get('routes/places/view/{id}', [RouteAPIController::class, 'getRoutePlaces']);
    Route::post('routes/stages/add', [RouteAPIController::class, 'addRouteStage']);
    Route::get('routes/stages/view/{id}', [RouteAPIController::class, 'getRouteStage']);
    Route::post('routes/stages/coords/add', [RouteAPIController::class, 'addRouteStageCoords']);
    Route::get('routes/termini', [TerminusAPIController::class, 'getTermini']);
    Route::post('routes/terminus/add', [TerminusAPIController::class, 'addTerminus']);
    //Queues
    Route::get('queues', [QueuesAPIController::class, 'getQueues']);
    Route::post('queues/add', [QueuesAPIController::class, 'addQueue']);
    Route::get('queues/places', [QueuesAPIController::class,'getQueuePlaces']);
    Route::get('queues/view/{id}', [QueuesAPIController::class, 'getQueue']);
    Route::get('queues/bookings/view/{id}', [QueuesAPIController::class, 'getQueueBookings']);
    Route::get('queues/statuses', [QueueStatusAPIController::class, 'getQueueStatuses']);
    Route::post('queues/statuses/add', [QueueStatusAPIController::class, 'addQueueStatus']);
    Route::get('queues/geofence', [QueuesAPIController::class, 'getGeofence']);
    Route::post('queues/complete/queue', [QueuesAPIController::class, 'completeQueue']);
    //Saccos
    Route::get('saccos', [SaccoAPIController::class, 'getSaccos']);
    Route::post('saccos/add', [SaccoAPIController::class, 'addSacco']);
    Route::get('saccos/members', [SaccoMembersAPIController::class, 'getMembers']);
    Route::post('saccos/members/add', [SaccoMembersAPIController::class, 'addMember']);
    Route::get('saccos/vehicles', [SaccoVehiclesAPIController::class, 'getSaccoVehicles']);
    Route::post('saccos/vehicles/add', [SaccoVehiclesAPIController::class, 'addVehicle']);
    Route::get('saccos/routes', [SaccoRoutesAPIController::class, 'getSaccoRoutes']);
    //dddd
    //Vehicles
    Route::get('vehicles', [VehiclesAPIController::class, 'getVehicles']);
    Route::post('vehicles/add', [VehiclesAPIController::class, 'addVehicle']);
    Route::get('vehicles/users', [VehicleUsersAPIController::class, 'getVehicleUsers']);
    Route::get('vehicles/seat_settings', [SeatsAPIController::class, 'getSeats']);

    //Bookings
    Route::get('bookings/passengers', [BookingsAPIController::class, 'getPassengerBookings']);
    Route::get('bookings/passengers/view/{id}', [BookingsAPIController::class, 'getPassengerBooking']);
    Route::get('bookings/passenger/pick/{id}', [BookingsAPIController::class,'pickPassenger']);
    Route::post('bookings/passengers/pick', [BookingsAPIController::class,'pickPassengers']);
    Route::get('bookings/parcels', [BookingsAPIController::class, 'getParcels']);
    //points
    Route::get('points', [PointsAPIController::class,'getPoints']);
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
