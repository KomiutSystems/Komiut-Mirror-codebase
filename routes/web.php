<?php

use App\Http\Controllers\Dashboard\Bookings\BookingsController;
use App\Http\Controllers\Dashboard\Crew\CrewController;
use App\Http\Controllers\Dashboard\Points\PointsController;
use App\Http\Controllers\Dashboard\Profile\ProfileController;
use App\Http\Controllers\Dashboard\Queues\QueuesController;
use App\Http\Controllers\Dashboard\Queues\QueueStatusController;
use App\Http\Controllers\Dashboard\Routes\TerminusController;
use App\Http\Controllers\Dashboard\Routes\TerminusSaccoController;
use App\Http\Controllers\Dashboard\Routes\TerminusUserController;
use App\Http\Controllers\Dashboard\Sacco\SaccoMembersController;
use App\Http\Controllers\Dashboard\Search\SearchController;
use App\Http\Controllers\Dashboard\Settings\GenderSettings;
use App\Http\Controllers\Dashboard\Settings\MpesaPaymentSettings;
use App\Http\Controllers\Dashboard\Settings\PointsSettingsController;
use App\Http\Controllers\Dashboard\Summaries\SummaryController;
use App\Http\Controllers\Dashboard\Transactions\TransactionController;
use App\Http\Controllers\Dashboard\Transactions\MpesaController;
use App\Http\Controllers\Dashboard\Transactions\CashController;
use App\Http\Controllers\Dashboard\Vehicles\VehicleSeatsController;
use App\Http\Controllers\Dashboard\Vehicles\VehiclesLocationController;
use App\Http\Controllers\Dashboard\Vehicles\VehicleUsersController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\IndexController;
use App\Http\Controllers\Dashboard\HomeController;
use App\Http\Controllers\Dashboard\Routes\PlaceController;
use App\Http\Controllers\Dashboard\Routes\RouteController;
//use App\Http\Controllers\Dashboard\Route\PlaceController;
//use App\Http\Controllers\Dashboard\Route\RouteController;
use App\Http\Controllers\Dashboard\Routes\RouteStageController;
use App\Http\Controllers\Dashboard\Sacco\SaccoController;
use App\Http\Controllers\Dashboard\Sacco\SaccoRouteController;
use App\Http\Controllers\Dashboard\Sacco\SaccoUserController;
use App\Http\Controllers\Dashboard\Sacco\SaccoVehiclesController;
use App\Http\Controllers\Dashboard\Users\PermissionsController;
use App\Http\Controllers\Dashboard\Users\RolesController;
use App\Http\Controllers\Dashboard\Users\UsersController;
use App\Http\Controllers\Dashboard\Vehicles\VehicleController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/',[IndexController::class, 'index']);
Route::get('/get/genders',[IndexController::class, 'getGenders']);
Route::get('/check-login',[IndexController::class, 'checkLogin']);

Auth::routes();

Route::get('home', [HomeController::class, 'index'])->name('home');
Route::get('/home/dashboard', [HomeController::class, 'getDashboard']);
//transactions
Route::group(['middleware'=>['permission:View Transactions']], function(){
    Route::get('transactions/all', [TransactionController::class, 'index']);
    Route::get('transactions/datatable/all', [TransactionController::class, 'getTransactions']);
    Route::get('transactions/mpesa', [MpesaController::class, 'index']);
    Route::get('transactions/datatable/mpesa', [MpesaController::class, 'getMpesa']);
    Route::post('transactions/mpesa/import', [MpesaController::class, 'import']);
    Route::get('transactions/cash', [CashController::class, 'index']);
    Route::get('transactions/datatable/cash', [CashController::class, 'getCash']);
});
//summaries
Route::get('summaries', [SummaryController::class, 'index']);
Route::get('datatable/summaries', [SummaryController::class, 'getSummaries']);
//routes management
Route::group(['middleware'=>['permission:View Routes|View Places|View Termini|View Termini Users']], function(){
    Route::get('routes/places',[PlaceController::class, 'index']);
    Route::post('routes/place/add',[PlaceController::class, 'addPlace']);
    Route::get('routes/datatable/places',[PlaceController::class, 'getPlaces']);
    Route::get('routes/places/view/{id}',[PlaceController::class, 'place']);

    Route::get('/routes',[RouteController::class, 'index']);
    Route::post('/route/add',[RouteController::class, 'addRoute']);
    Route::get('/datatable/routes',[RouteController::class, 'getRoutes']);
    Route::get('/routes/search/places',[RouteController::class, 'searchPlaces']);
    Route::get('routes/search',[RouteController::class, 'searchRoutes']);

    Route::get('/routes/view/{id}',[RouteStageController::class, 'index']);
    Route::post('/routes/stage/add',[RouteStageController::class, 'addRouteStage']);
    Route::get('/datatable/route/stages',[RouteStageController::class, 'getRouteStages']);
    Route::post('/route/stage/remove/{id}',[RouteStageController::class, 'removeRouteStage']);
    Route::get('/routes/stages/view/{id}',[RouteStageController::class, 'viewRouteStage']);
    Route::post('/routes/stage/update',[RouteStageController::class, 'updateRouteStage']);
    Route::get('/routes/view/map/{id}',[RouteStageController::class, 'viewRouteMap']);

    Route::get("routes/termini", [TerminusController::class, 'index']);
    Route::get("routes/datatable/termini", [TerminusController::class, 'getTermini']);
    Route::post("routes/terminus/add", [TerminusController::class, 'addTerminus']);

    Route::get('routes/termini/users', [TerminusUserController::class,'index']);
    Route::get('routes/termini/datatable/users', [TerminusUserController::class,'getTerminiUsers']);
    Route::post('routes/termini/user/add', [TerminusUserController::class,'addTerminusUser']);

    Route::get('routes/termini/saccos', [TerminusSaccoController::class,'index']);
    Route::get('routes/termini/datatable/saccos', [TerminusSaccoController::class,'getTerminiSaccos']);
    Route::post('routes/termini/saccos/add', [TerminusSaccoController::class,'addTerminusSacco']);
});
//users
Route::get('/users/all', [UsersController::class, 'index']);
Route::post('/users/add', [UsersController::class, 'addUser']);
Route::get('/users/datatable/all', [UsersController::class, 'getUsers']);
Route::get('/users/roles', [RolesController::class, 'index']);
Route::get('/users/datatable/roles', [RolesController::class, 'getRoles']);
Route::get('/users/search/roles', [RolesController::class, 'searchRoles']);
Route::post('/users/roles/add', [RolesController::class, 'addRole']);
Route::get('/users/roles/view/{id}', [RolesController::class, 'viewRole']);
Route::post('users/roles/permissions/add' ,[RolesController::class, 'addPermissions']);
Route::get('/users/permissions', [PermissionsController::class, 'index']);
Route::get('/users/datatable/permissions', [PermissionsController::class, 'getPermissions']);
Route::post('/users/permissions/add', [PermissionsController::class, 'addPermission']);
//Route::get('/users/search/users', [UsersController::class, 'searchUser']);

//Sacco management
Route::get('/saccos/all',[SaccoController::class, 'index']);
Route::post('/sacco/add',[SaccoController::class, 'create']);
Route::get('/datatable/saccos',[SaccoController::class, 'getSaccos']);

Route::get('saccos/members', [SaccoMembersController::class, 'index']);
Route::post('saccos/member/add', [SaccoMembersController::class, 'addMember']);
Route::get('saccos/datatable/members', [SaccoMembersController::class, 'getMembers']);

Route::get('saccos/vehicles', [SaccoVehiclesController::class, 'index']);
Route::post('saccos/vehicle/add', [SaccoVehiclesController::class, 'addVehicle']);
Route::get('saccos/datatable/vehicles', [SaccoVehiclesController::class, 'getVehicles']);

Route::get('saccos/routes', [SaccoRouteController::class, 'index']);
Route::post('saccos/route/add', [SaccoRouteController::class, 'addSaccoRoute']);
Route::get('saccos/datatable/routes', [SaccoRouteController::class, 'getRoutes']);
/*
Route::get('/sacco',[SaccoController::class, 'index']);
Route::get('/saccos/view/{id}', [SaccoController::class, 'sacco']);

Route::post('/sacco/vehicle/add',[SaccoVehiclesController::class, 'addSaccoVehicle']);
Route::post('/sacco/vehicle/remove',[SaccoVehiclesController::class, 'removeSaccoVehicle']);

Route::post('/sacco/route/add',[SaccoRouteController::class, 'addSaccoRoute']);
Route::get('/sacco/route/remove/{id}',[SaccoRouteController::class, 'removeSaccoRoute']);

Route::post('/sacco/member/add',[SaccoUserController::class, 'addSaccoUser']);
Route::post('/sacco/member/remove',[SaccoUserController::class, 'removeSaccoUser']);

Route::get('/datatable/sacco/vehicles/{id}',[SaccoVehiclesController::class, 'getSaccoVehicles']);
Route::get('/datatable/sacco/users/{id}',[SaccoUserController::class, 'getSaccoUsers']);
Route::get('/datatable/sacco/routes/{id}',[SaccoRouteController::class, 'getSaccoRoutes']);
*/
//vehicles
Route::get('/vehicles/all',[VehicleController::class, 'index']);
Route::post('/vehicle/add',[VehicleController::class, 'create']);
Route::get('/datatable/vehicles',[VehicleController::class, 'getVehicles']);
Route::get('/vehicles/search',[VehicleController::class,'searchVehicles']);

Route::get('vehicles/users', [VehicleUsersController::class, 'index']);
Route::get('vehicles/datatable/users', [VehicleUsersController::class, 'getVehicleUsers']);
Route::post('vehicles/user/add', [VehicleUsersController::class, 'addVehicleUser']);

Route::get('vehicles/locations', [VehiclesLocationController::class, 'index']);

Route::get('vehicles/seats/settings', [VehicleSeatsController::class, 'index']);
Route::post('vehicles/seats/settings/add', [VehicleSeatsController::class, 'addSeat']);
Route::get('vehicles/datatable/seats/settings', [VehicleSeatsController::class, 'getSeatSettings']);
Route::get('vehicles/search/seats', [VehicleSeatsController::class, 'searchSeats']);
Route::get('vehicles/seats/settings/view/{id}', [VehicleSeatsController::class, 'viewSeatSetting']);
Route::post('vehicles/seats/settings/arrangement/add', [VehicleSeatsController::class, 'addSeatArrangement']);
Route::get('vehicles/datatable/seats/settings/arrangements/{id}', [VehicleSeatsController::class, 'getSeatArrangements']);

//Bookings
Route::get('bookings/passengers', [BookingsController::class, 'index']);
Route::get('bookings/datatable/passengers', [BookingsController::class, 'getPassengerBookings']);
Route::get('bookings/parcels', [BookingsController::class, 'parcels']);
Route::get('bookings/datatable/parcels', [BookingsController::class, 'getParcels']);
Route::post('bookings/parcels/add', [BookingsController::class, 'addParcel']);

//Points
Route::get('points', [PointsController::class, 'index']);
Route::get('points/datatable/points', [PointsController::class, 'getPoints']);

//Queues
Route::get('queues/all', [QueuesController::class, 'index']);
Route::get('queues/datatable/queues', [QueuesController::class, 'getQueues']);
Route::post('queues/add', [QueuesController::class, 'addQueue']);
Route::get('queues/view/{id}', [QueuesController::class, 'viewQueue']);
Route::get('queues/search/places/{id}', [QueuesController::class, 'searchPlaces']);
Route::post('queues/passenger/add', [QueuesController::class, 'addPassengerBooking']);
Route::get('queues/bookings/booked_seats/{id}', [QueuesController::class, 'getBookedSeats']);
Route::get('queues/datatable/bookings/passengers/{id}', [QueuesController::class, 'getPassengerBookings']);
Route::get('queues/statuses', [QueueStatusController::class, 'index']);
Route::get('queues/datatable/statuses', [QueueStatusController::class, 'getQueueStatuses']);
Route::post('queues/status/add',[QueueStatusController::class,'addQueueStatus']);
Route::get('queues/statuses/search',[QueueStatusController::class,'searchQueueStatuses']);
//Crews
Route::get('crews', [CrewController::class, 'index']);
Route::get('datatable/crews', [CrewController::class, 'getCrews']);
Route::post('crews/add', [CrewController::class, 'addCrew']);
//Settings
Route::get('settings/gender', [GenderSettings::class, 'index']);
Route::get('settings/datatable/gender', [GenderSettings::class, 'getGenders']);
Route::post('settings/gender/add', [GenderSettings::class, 'addGender']);

Route::get('settings/mpesa', [MpesaPaymentSettings::class, 'index']);
Route::get('settings/datatable/mpesa', [MpesaPaymentSettings::class, 'getSettings']);
Route::post('settings/mpesa/add', [MpesaPaymentSettings::class, 'addSettings']);

Route::get('settings/points', [PointsSettingsController::class, 'index']);
Route::get('settings/datatable/points', [PointsSettingsController::class, 'getPointSettings']);
Route::post('settings/points/add', [PointsSettingsController::class, 'addPointsSettings']);
Route::get('settings/points/search/roles', [PointsSettingsController::class, 'searchRoles']);
//profile
Route::get('profile', [ProfileController::class, 'index']);
Route::post('profile/change', [ProfileController::class, 'editProfile']);
Route::post('profile/change/password', [ProfileController::class, 'changePassword']);
Route::post('profile/upload/picture', [ProfileController::class, 'uploadProfilePicture']);

//Search
Route::get('dashboard/search/roles', [SearchController::class, 'searchRoles']);
Route::get('saccos/search',[SearchController::class, 'searchSaccos']);
Route::get("routes/termini/search", [SearchController::class, 'searchTermini']);
Route::get('users/search/users', [SearchController::class, 'searchUser']);