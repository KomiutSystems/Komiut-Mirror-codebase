<?php

use App\Http\Controllers\APIs\Auth\DriverAuthController;
use App\Http\Controllers\APIs\Auth\PasswordResetController;
use App\Http\Controllers\APIs\Auth\SocialAuthController;
use App\Http\Controllers\APIs\AuthController;
use App\Http\Controllers\APIs\BillingMpesaController;
use App\Http\Controllers\APIs\CoopRestPaymentsController;
use App\Http\Controllers\APIs\Dashboard\Billing\BillingAdminController;
use App\Http\Controllers\APIs\Dashboard\BookARide\BookARideQueuesAPIController;
use App\Http\Controllers\APIs\Dashboard\BookARide\BookARideRoutesAPIController;
use App\Http\Controllers\APIs\Dashboard\BookARide\BookARideSaccoRoutesAPIController;
use App\Http\Controllers\APIs\Dashboard\BookARide\BookARideSeatController;
use App\Http\Controllers\APIs\Dashboard\BookARide\BroadcastReservationController;
use App\Http\Controllers\APIs\Dashboard\BookARide\FareAPIController;
use App\Http\Controllers\APIs\Dashboard\BookARide\TripManifestController;
use App\Http\Controllers\APIs\Dashboard\BookARide\VehicleLocationController;
use App\Http\Controllers\APIs\Dashboard\BookARide\VehicleLocationsReadController;
use App\Http\Controllers\APIs\Dashboard\Bookings\BookingsAPIController;
use App\Http\Controllers\APIs\Dashboard\Crew\CrewAPIController;
use App\Http\Controllers\APIs\Dashboard\ExpenseAndFees\ExpenseAndFeesAPIController;
use App\Http\Controllers\APIs\Dashboard\HomeAPIController;
use App\Http\Controllers\APIs\Dashboard\Loyalty\LoyaltyController;
use App\Http\Controllers\APIs\Dashboard\Loyalty\LoyaltyHoldersController;
use App\Http\Controllers\APIs\Dashboard\Mpesa\MpesaDashboardController;
use App\Http\Controllers\APIs\Dashboard\Mpesa\PaymentSettingsController;
use App\Http\Controllers\APIs\Dashboard\Points\PointsAPIController;
use App\Http\Controllers\APIs\Dashboard\Profiles\ProfileAPIController;
use App\Http\Controllers\APIs\Dashboard\QRCode\QRCodeApiController;
use App\Http\Controllers\APIs\Dashboard\Queues\QueuesAPIController;
use App\Http\Controllers\APIs\Dashboard\Queues\QueueStatusAPIController;
use App\Http\Controllers\APIs\Dashboard\Routes\PlaceAPIController;
use App\Http\Controllers\APIs\Dashboard\Routes\RouteAPIController;
use App\Http\Controllers\APIs\Dashboard\Routes\TerminusAPIController;
use App\Http\Controllers\APIs\Dashboard\Saccos\AnnouncementsController;
use App\Http\Controllers\APIs\Dashboard\Saccos\ResourceStateController;
use App\Http\Controllers\APIs\Dashboard\Saccos\SaccoAPIController;
use App\Http\Controllers\APIs\Dashboard\Saccos\SaccoBillingController;
use App\Http\Controllers\APIs\Dashboard\Saccos\SaccoFaresAPIController;
use App\Http\Controllers\APIs\Dashboard\Saccos\SaccoLoyaltyController;
use App\Http\Controllers\APIs\Dashboard\Saccos\SaccoMembersAPIController;
use App\Http\Controllers\APIs\Dashboard\Saccos\SaccoRoutesAPIController;
use App\Http\Controllers\APIs\Dashboard\Saccos\SaccoVehiclesAPIController;
use App\Http\Controllers\APIs\Dashboard\Saccos\TillRequestsController;
use App\Http\Controllers\APIs\Dashboard\Settings\ActivityLogController;
use App\Http\Controllers\APIs\Dashboard\Settings\ExpenseAndFeesSettingsAPIController;
use App\Http\Controllers\APIs\Dashboard\Settings\GenderAPIController;
use App\Http\Controllers\APIs\Dashboard\Settings\RolesController;
use App\Http\Controllers\APIs\Dashboard\Summaries\SummariesAPIController;
use App\Http\Controllers\APIs\Dashboard\Transactions\CashAPIController;
use App\Http\Controllers\APIs\Dashboard\Transactions\MpesaAPIController;
use App\Http\Controllers\APIs\Dashboard\Transactions\TransactionsAPIController;
use App\Http\Controllers\APIs\Dashboard\Users\RoleAPIController;
use App\Http\Controllers\APIs\Dashboard\Users\UsersAPIController;
use App\Http\Controllers\APIs\Dashboard\Vehicles\SeatsAPIController;
use App\Http\Controllers\APIs\Dashboard\Vehicles\VehiclesAPIController;
use App\Http\Controllers\APIs\Dashboard\Vehicles\VehicleUsersAPIController;
use App\Http\Controllers\APIs\Driver\DriverCashController;
use App\Http\Controllers\APIs\Driver\DriverOnboardingController;
use App\Http\Controllers\APIs\Driver\DriverPortalController;
use App\Http\Controllers\APIs\Driver\DriverQueueController;
use App\Http\Controllers\APIs\Driver\DriverRoutesController;
use App\Http\Controllers\APIs\Driver\DriverTripController;
use App\Http\Controllers\APIs\Driver\DriverTripHistoryController;
use App\Http\Controllers\APIs\IndexApiController;
use App\Http\Controllers\APIs\MpesaPaymentsController;
use App\Http\Controllers\APIs\C2bConfirmationController;
use App\Http\Controllers\APIs\NCBARestPaymentsController;
use App\Http\Controllers\APIs\NCBASoapPaymentsController;
use App\Http\Controllers\APIs\Notifications\DeviceController;
use App\Http\Controllers\APIs\Notifications\NotificationsController;
use App\Http\Controllers\APIs\Partner\BankLeadsController;
use App\Http\Controllers\APIs\Partner\BankWriteBackController;
use App\Http\Controllers\APIs\Passenger\PassengerPaymentsController;
use App\Http\Controllers\APIs\Payments\StkStatusController;
use App\Http\Controllers\APIs\Profile\ProfileUpdateController;
use App\Http\Controllers\APIs\Sacco\SaccoDirectoryController;
use App\Http\Controllers\Services\SendFCMMessageController;
use App\Http\Middleware\CheckAPIUserStatus;
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
/*
 * Load-balancer health probe: GET /api/up
 *
 * Registered HERE, at the top level of api.php, on purpose:
 *
 *  - NOT in routes/web.php. That file is loaded with the 'web' middleware group,
 *    which App\Http\Kernel deliberately does not define (no cookies/sessions/CSRF
 *    in an API-only service). A route there fails with
 *    "Target class [web] does not exist" and returns 500.
 *
 *  - NOT inside the brand groups below. Those apply ResolveBrand, which resolves
 *    the brand from the Host header or X-App-Key and FAILS CLOSED with a 404 when
 *    neither matches. An ALB health check arrives with Host set to the target's
 *    private IP and no app key, so it can never satisfy that — every healthy
 *    instance would be marked unhealthy and replaced by the ASG.
 *
 * DELIBERATELY SHALLOW: it reports "PHP is up and routing" and touches neither
 * the database nor Redis. A dependency check here would be actively harmful —
 * one RDS blip fails the probe on every instance at once, the ASG replaces all
 * of them, and a brief degradation becomes a total outage. Depth belongs in the
 * CloudWatch alarms on RDS/ElastiCache, not in the check that decides whether to
 * terminate servers.
 */
Route::get('up', fn () => response()->json(['status' => 'ok'], 200));

Route::group([/* 'middleware'=>['api'] */], function ($router) {
    /*
    |--------------------------------------------------------------------------
    | REMOVED: cross-environment data-migration endpoints
    |--------------------------------------------------------------------------
    |
    | The "copy" and "copy/from" routes were removed on security grounds.
    | They sat in this group with NO authentication - the `api` middleware
    | group is throttle + route-binding only, it has never included auth - so
    | anyone on the internet could call them.
    |
    | The worst offenders:
    |   users/passwords/copy/from  -> dumped every user's email + bcrypt hash
    |   users/copy/from            -> dumped every user record (full PII)
    | and the inbound "copy" routes pulled data from https://test.komiut.com
    | straight into this database, unauthenticated.
    |
    | The controller methods still exist untouched in IndexApiController. If
    | this tooling is needed again, re-register these routes behind
    | `auth:sanctum` plus a super-admin gate - do NOT restore them bare.
    |
    */

    /*
    | Payment CALLBACK / webhook endpoints (NCBA, Coop, Daraja STK).
    |
    | These arrive from the banks' / Safaricom's servers with NO X-App-Key and
    | not on a brand hostname, so ResolveBrand cannot identify them. Instead the
    | brand is carried in a `{brand}` URL segment and resolved by `brand.route`,
    | which activates the correct per-brand database before the handler runs.
    | Each brand must therefore register its OWN callback URLs in the bank / Daraja
    | portal, e.g. `/api/komiut/rest/mpesa/confirmation`, `/api/safiri/coop/mpesa`.
    |
    | `{brand}` is constrained to lowercase letters; an unknown brand => 404.
    */
    /*
    | NCBA push-notification confirmation, on the fixed brand-LESS URL.
    |
    | REGISTERED BEFORE the `{brand}` group below, and that ordering is the
    | whole point. NCBA's letter names
    | `komiut.com/api/rest/mpesa/confirmation_new`. Against a `{brand}` prefix
    | carrying `mpesa/confirmation_new`, that path also parses as
    | brand="rest" — a perfectly good `[a-z]+` match — so while the branded
    | group was registered first it captured this URL, `brand.route` failed to
    | resolve a brand called "rest", and every confirmation NCBA sent to the
    | address in their own letter came back 404. Laravel matches in
    | registration order, so declaring the literal path first settles it.
    |
    | No ambiguity is introduced: the branded form has one more path segment
    | (`/api/komiut/rest/mpesa/confirmation_new`), so it cannot match here.
    |
    | Brand is resolved from the host (komiut.com => komiut) by the `brand`
    | middleware; the handler then authenticates the bank-issued
    | Username/Password before recording anything.
    */
    Route::any('rest/mpesa/confirmation_new', [NCBARestPaymentsController::class, 'restMpesaNewPayments'])
        ->middleware('brand');
    Route::any('mpesa/confirmation_new', [NCBARestPaymentsController::class, 'mpesaNewPayments'])
        ->middleware('brand');

    /*
    | Safaricom C2B, per-till. THE FLEET'S MAIN INGESTION PATH.
    |
    | ~98.6% of revenue arrives on URLs of this exact shape. The legacy payment
    | tier registers every till with Safaricom itself, as
    | `{APP_URL}/api/confirmation/{mpesa_setting_id}`, and 1,336,113 confirmations
    | have been delivered to that path. Answering the same shape here is what lets
    | a till be migrated by re-registering it — and rolled back the same way, one
    | till at a time, with no DNS change and no bank involvement.
    |
    | `{id}` is the legacy setting id. It is recorded, never trusted for
    | attribution — see C2bConfirmationController.
    |
    | Registered ABOVE the `{brand}` group for the same reason the NCBA routes
    | are: `{brand}` matched `[a-z]+` and would otherwise be free to swallow a
    | literal first segment. These paths lead with `confirmation`/`validation`,
    | which are not brands.
    */
    Route::any('confirmation/{id}', [C2bConfirmationController::class, 'confirmation'])
        ->where('id', '[0-9]+')->middleware('brand');
    Route::any('validation/{id}', [C2bConfirmationController::class, 'validation'])
        ->where('id', '[0-9]+')->middleware('brand');

    Route::prefix('{brand}')
        ->middleware('brand.route')
        ->where(['brand' => '[a-z]+'])
        ->group(function ($router) {
            // NCBA Endpoints
            Route::any('mpesa/confirmation', [NCBASoapPaymentsController::class, 'mpesaPayments']);
            Route::any('rest/mpesa/confirmation', [NCBARestPaymentsController::class, 'restMpesaPayments']);
            Route::any('rest/mpesa/confirmation_new', [NCBARestPaymentsController::class, 'restMpesaNewPayments']);
            Route::any('mpesa/confirmation_new', [NCBARestPaymentsController::class, 'mpesaNewPayments']);

            // Coop Endpoints
            Route::any('coop/mpesa', [CoopRestPaymentsController::class, 'coopMpesaPayments']);
            Route::any('coop/stk/response', [CoopRestPaymentsController::class, 'coopMpesaStkCallback']);

            // SACCO subscription billing — C2B (SACCO pays invoice_number as the
            // account reference). Unauthenticated + unsigned: defence is in
            // InvoiceService (receipt dedupe + amount clamp + record-then-recompute).
            Route::any('billing/mpesa/validation', [BillingMpesaController::class, 'validation']);
            Route::any('billing/mpesa/confirmation', [BillingMpesaController::class, 'confirmation']);

            /*
            | Daraja STK callback. Keyed by an unguessable per-payment nonce.
            |
            | The old form was `stk/push/response?booking_id=<sequential id>` on an
            | unauthenticated route, and the handler trusted that id directly - so
            | anyone could POST a forged success payload for a guessed booking and get
            | a free ride. The booking is now resolved from the stored push record.
            */
            Route::post('stk/push/response/{nonce}', [MpesaPaymentsController::class, 'stkResponse'])
                ->where('nonce', '[a-f0-9]{64}');
        });

    // Brand-guarded (resolves brand from X-App-Key). Without this it would 500,
    // since customerQRCodeSTKPush builds a CallBackURL from the active Brand.
    // Prefer the brand-scoped /api/auth/qrcode/stk/push; this standalone path is
    // kept for existing clients but now requires the X-App-Key header.
    Route::post('qrcode/stk/push', [MpesaPaymentsController::class, 'customerQRCodeSTKPush'])
        ->middleware(['brand', 'auth:sanctum']);

    // (The brand-less NCBA confirmation routes were moved ABOVE the `{brand}`
    // group — see the comment there for why the order is load-bearing.)

    /*
    | Legacy STK callback path, retained ONLY to log forgery attempts; it never
    | marks anything paid. It stays OUTSIDE the `{brand}` prefix on purpose: the
    | old callbacks it exists to catch were sent with no brand segment. Remove it
    | once no in-flight pushes can still reference it.
    */
    Route::any('stk/push/response', [MpesaPaymentsController::class, 'stkResponseLegacy']);
    Route::any('fcm/notification/test', [SendFCMMessageController::class, 'sendTestNotification']);
    Route::any('payments/notifications/test', [MpesaPaymentsController::class, 'paymentsNotification']);
});

// Every mobile/web brand-facing route resolves its brand FIRST (by X-App-Key
// header for the apps, hostname for the web), before auth — because the users
// table lives inside the brand database. Unknown brand => 404.
//
// The payment CALLBACK routes above are deliberately NOT in this group: NCBA /
// Coop / Daraja post from their own servers with no X-App-Key, so they resolve
// their brand differently (see the callback brand-routing follow-up). They stay
// on the default connection for now, preserving current single-database behaviour.
// The mobile/web consumer API. Registered under BOTH the legacy `auth` prefix
// and the versioned `v1/auth` prefix so existing callers keep working while the
// apps migrate to the versioned path. `/api/v1/auth/...` is the canonical URL
// new clients should wire against; drop the legacy alias once nothing uses it.
$mobileApi = function ($router) {
    // QR fare STK must be authenticated: customerQRCodeSTKPush stamps the payment
    // with auth()->id() so the passenger can poll its own status (StkStatusController
    // ownership check). Without auth the record is ownerless and the poll 404s.
    Route::post('qrcode/stk/push', [MpesaPaymentsController::class, 'customerQRCodeSTKPush'])
        ->middleware(['auth:sanctum', 'user_status_api']);
    Route::any('mpesa/stk', [MpesaPaymentsController::class, 'customerMpesaSTKPush']);
    Route::get('genders', [IndexApiController::class, 'getGenders']);
    // Auth
    // Throttled: these are public, credential-checking / record-creating endpoints.
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:15,1');
    // SACCO self-registration (creates the SACCO + its first admin, then logs in)
    // Throttled: public, credential-creating, and it CLAIMS a tenant. The claim
    // itself is gated in SaccoDirectory::claimableByName; this bounds how fast
    // someone can walk SACCO names looking for a stub to take.
    Route::post('register/sacco', [AuthController::class, 'registerSacco'])
        ->middleware('throttle:5,1');
    Route::post('reset_password', [AuthController::class, 'resetPassword']);   // mobile: phone + SMS
    // Dashboard (SACCO) email password reset: request a link, then set a new password.
    Route::post('forgot-password', [PasswordResetController::class, 'forgot']);
    Route::post('reset-password', [PasswordResetController::class, 'reset']);
    // Passenger social sign-in (Google / Apple). Brand is resolved by the group
    // middleware; the controller hard-locks this to passenger accounts only.
    Route::post('social/{provider}', [SocialAuthController::class, 'handle'])
        ->where('provider', '[a-z]+');
    // Daily driver check-in: phone + vehicle number plate (no password).
    //
    // throttle:login for the same reason the password route carries it, only
    // more so: there IS no password here, so the whole credential is a phone
    // number and a number plate — both public, both patterned (07XX…, KDA123X).
    // DriverLoginBurst watches for exactly that guessing and raises an alert,
    // but an alert is not a brake; without this, a guesser gets the `api`
    // group's 60 tries a minute per IP. The limiter keys on identifier + IP,
    // and this route sends `phone`, so a stage full of drivers on one NAT'd
    // connection still gets 8 tries each rather than 8 between them.
    Route::post('driver/login', [DriverAuthController::class, 'login'])
        ->middleware('throttle:login');
    // Street onboarding, run by a marketing agent standing with the driver.
    // Necessarily pre-authentication — neither the driver nor (usually) their
    // SACCO has an account yet. Public and record-creating, hence throttled.
    Route::post('driver/onboard', [DriverOnboardingController::class, 'store'])
        ->middleware('throttle:20,1');
    // SACCO directory type-ahead. Deliberately pre-authentication: a driver picks
    // their SACCO during onboarding, before they have a token. Public, so it is
    // throttled and returns id + name only — never the SACCO's contact details.
    Route::get('saccos/directory', [SaccoDirectoryController::class, 'index'])
        ->middleware('throttle:60,1');
    // Reserve a seat on a vehicle that is already on the road ("pick as you go").
    // Sibling of the book_a_ride/* routes below, but registered HERE rather than
    // inside the user_status_api group on purpose: group middleware runs before
    // route middleware, so inside that group CheckAPIUserStatus would reach
    // Auth::user()->status on a null user and return 500 to an unauthenticated
    // caller instead of 401. Listing the two explicitly fixes the order —
    // authenticate, then check the account is active. Same two checks the
    // siblings get, in the order they should always have run.
    Route::post('book_a_ride/broadcast/reserve', [BroadcastReservationController::class, 'reserve'])
        ->middleware(['auth:sanctum', 'user_status_api']);
    Route::group(['middleware' => 'user_status_api'], function ($router) {
        // dashboard controller
        Route::get('dashboard', [HomeAPIController::class, 'getDashboard']);
        // Book a ride
        Route::get('book_a_ride/routes', [BookARideRoutesAPIController::class, 'getRoutes']);
        Route::get('book_a_ride/route_saccos', [BookARideSaccoRoutesAPIController::class, 'getSaccoRoutes']);
        Route::get('book_a_ride/queues', [BookARideQueuesAPIController::class, 'getQueues']);
        Route::get('book_a_ride/seats', [BookARideSeatController::class, 'getVehicleSeats']);
        Route::get('book_a_ride/fare', [FareAPIController::class, 'getFare']);
        Route::post('book_a_ride/booking/add', [BookARideQueuesAPIController::class, 'addBooking']);
        // Live tracking (Reverb realtime)
        // Throttled: this is the highest-volume, least-trusted endpoint in the
        // app -- hundreds of driver phones on poor networks, each ping costing a
        // SELECT, an UPSERT and a Reverb broadcast. A retry loop on one handset
        // would otherwise have no ceiling. 60/min is ~5s between pings with
        // headroom; the map only needs one position every few seconds.
        Route::post('book_a_ride/location', [VehicleLocationController::class, 'broadcastLocation'])
            ->middleware('throttle:60,1');
        Route::post('book_a_ride/location/stop', [VehicleLocationController::class, 'stopBroadcasting']);
        Route::get('book_a_ride/nearby', [VehicleLocationController::class, 'nearby']);
        // The live-map read: this SACCO's current fleet positions. Scoped by the
        // model (VehicleLocation reaches sacco/brand via its vehicle), so the
        // vehicle_ids filter narrows an already-scoped set and cannot widen it.
        Route::get('vehicle_locations', [VehicleLocationsReadController::class, 'index']);
        Route::get('book_a_ride/manifest/{id}', [TripManifestController::class, 'manifest']);
        // Loyalty (points + free-ride redemption)
        Route::get('book_a_ride/loyalty/summary', [LoyaltyController::class, 'summary']);
        Route::get('book_a_ride/loyalty/history', [LoyaltyController::class, 'history']);
        Route::post('book_a_ride/loyalty/redeem', [LoyaltyController::class, 'redeem']);
        // Qr Code
        Route::get('qrcode/payments', [QRCodeApiController::class, 'getQRCodePayments']);
        Route::post('qrcode/vehicle', [QRCodeApiController::class, 'getVehicle']);
        Route::post('qrcode/redeem_points', [QRCodeApiController::class, 'redeemPoints']);
        // Signed fare-QR: SACCO/driver generates a vehicle's token; passenger resolves a scan.
        Route::get('qrcode/vehicle/{vehicle}/token', [QRCodeApiController::class, 'vehicleToken']);
        Route::post('qrcode/resolve', [QRCodeApiController::class, 'resolveToken']);
        // STK payment status poll + cancel (passenger). checkout = CheckoutRequestID.
        Route::get('mpesa/stk/status/{checkout}', [StkStatusController::class, 'status']);
        Route::post('mpesa/stk/cancel/{checkout}', [StkStatusController::class, 'cancel']);
        // Transactions
        Route::get('transactions', [TransactionsAPIController::class, 'getTransactions'])->middleware('permission:View Transactions');
        Route::get('transactions/mpesa', [MpesaAPIController::class, 'getTransactions'])->middleware('permission:View Transactions');
        Route::get('transactions/cash', [CashAPIController::class, 'getTransactions'])->middleware('permission:View Transactions');
        // M-Pesa payments dashboard (web): per-SACCO settings + tills + tiles
        Route::get('mpesa/settings', [PaymentSettingsController::class, 'show'])->middleware('permission:View Payment Settings');
        Route::post('mpesa/settings', [PaymentSettingsController::class, 'upsert']);
        Route::get('mpesa/tills', [MpesaDashboardController::class, 'tills'])->middleware('permission:View Payment Settings');
        Route::get('mpesa/stats', [MpesaDashboardController::class, 'stats'])->middleware('permission:View Transactions');
        // Summaries
        // permission gate is REQUIRED here, not decorative: SaccoScope does not
        // apply to users with no home SACCO (passengers/drivers), so without it
        // any authenticated passenger got an unscoped read of every SACCO's
        // revenue in the brand.
        Route::get('summaries', [SummariesAPIController::class, 'getSummaries'])
            ->middleware('permission:View Summaries');
        Route::get('summaries/export', [SummariesAPIController::class, 'export'])
            ->middleware('permission:View Summaries');
        // routes
        Route::get('routes/places', [PlaceAPIController::class, 'getPlaces'])->middleware('permission:View Places');
        Route::post('routes/place/add', [PlaceAPIController::class, 'addPlace']);
        Route::get('routes/place/view/{id}', [PlaceAPIController::class, 'getPlace'])->middleware('permission:View Places');
        Route::get('routes', [RouteAPIController::class, 'getRoutes'])->middleware('permission:View Routes');
        Route::post('routes/add', [RouteAPIController::class, 'addRoute']);
        Route::get('routes/places/view/{id}', [RouteAPIController::class, 'getRoutePlaces'])->middleware('permission:View Routes');
        Route::post('routes/stages/add', [RouteAPIController::class, 'addRouteStage'])->middleware('permission:Add Routes|Edit Routes');
        Route::get('routes/stages/view/{id}', [RouteAPIController::class, 'getRouteStage'])->middleware('permission:View Routes');
        Route::post('routes/stages/coords/add', [RouteAPIController::class, 'addRouteStageCoords'])->middleware('permission:Edit Routes');
        Route::get('routes/termini', [TerminusAPIController::class, 'getTermini'])->middleware('permission:View Termini');
        Route::post('routes/terminus/add', [TerminusAPIController::class, 'addTerminus']);
        // Queues
        Route::get('queues', [QueuesAPIController::class, 'getQueues'])->middleware('permission:View Queues');
        Route::post('queues/add', [QueuesAPIController::class, 'addQueue']);
        Route::get('queues/places', [QueuesAPIController::class, 'getQueuesPlaces'])->middleware('permission:View Queues');
        Route::get('queues/view/{id}', [QueuesAPIController::class, 'getQueue'])->middleware('permission:View Queues');
        Route::get('queues/bookings/view/{id}', [QueuesAPIController::class, 'getQueueBookings'])->middleware('permission:View Queues');
        // Reference enumeration (Pending/Active/Completed/Cancelled/Suspended), not
        // data. Gating it behind a narrow permission only produces 403s for the
        // driver app, which needs the labels to render a queue at all, and leaks
        // nothing. Auth alone is the right bar.
        Route::get('queues/statuses', [QueueStatusAPIController::class, 'getQueueStatuses']);
        Route::post('queues/statuses/add', [QueueStatusAPIController::class, 'addQueueStatus']);
        // Self-scoped: returns only the queue and vehicles assigned to the CALLER
        // via vehicle_user. Crew need it to work a shift; a permission adds
        // nothing it does not already enforce by identity.
        Route::get('queues/geofence', [QueuesAPIController::class, 'getGeofence']);
        Route::post('queues/complete/queue', [QueuesAPIController::class, 'completeQueue'])->middleware('permission:Edit Queues');
        // Driver-facing queue/trip lifecycle: vehicle + fare + status are derived
        // server-side from the driver's assignment (see DriverQueueController).
        Route::post('queues/join', [DriverQueueController::class, 'join'])->middleware('permission:Edit Queues');
        Route::post('queues/exit', [DriverQueueController::class, 'exit'])->middleware('permission:Edit Queues');
        Route::post('trips/start', [DriverQueueController::class, 'startTrip'])->middleware('permission:Edit Queues');
        Route::get('trips/bookings', [DriverQueueController::class, 'bookings'])->middleware('permission:Edit Queues');

        // The driver app's own screens. Gated by IDENTITY, not permission: each
        // one reads only the vehicle the caller is currently assigned to, so the
        // assignment IS the boundary. A permission would add nothing and would
        // 403 the 206 migrated crew, who hold Conductor and so lack Edit Queues.
        Route::get('driver/home', [DriverPortalController::class, 'home']);
        Route::get('driver/earnings', [DriverPortalController::class, 'earnings']);
        Route::get('driver/routes', [DriverRoutesController::class, 'index']);
        // Past trips for the driver's vehicle (completed/cancelled + per-trip takings).
        Route::get('driver/trips', [DriverTripHistoryController::class, 'index']);
        // End-of-shift cash-in-hand declaration (one per vehicle per business day).
        Route::put('driver/cash', [DriverCashController::class, 'submit']);
        Route::get('driver/transactions', [DriverPortalController::class, 'transactions']);
        Route::get('driver/bookings', [DriverPortalController::class, 'bookings']);
        Route::get('driver/expenses', [DriverPortalController::class, 'expenses']);
        Route::post('driver/expenses', [DriverPortalController::class, 'storeExpense']);

        // The write half of a shift. Nothing here takes a vehicle or queue id:
        // each resolves the target from the caller's own assignment, which IS
        // the authorization model. The dashboard equivalents take an arbitrary
        // id with no ownership check, so a driver could close another bus's run
        // or board a passenger on somebody else's manifest.
        Route::get('driver/trip', [DriverTripController::class, 'show']);
        Route::post('driver/trip/end', [DriverTripController::class, 'end']);
        Route::post('driver/bookings/{booking}/cash', [DriverTripController::class, 'confirmCash'])->whereNumber('booking');
        Route::post('driver/bookings/{booking}/mark', [DriverTripController::class, 'markBooking'])->whereNumber('booking');
        // Saccos
        Route::get('saccos', [SaccoAPIController::class, 'getSaccos'])->middleware('permission:View Saccos');
        Route::post('saccos/add', [SaccoAPIController::class, 'addSacco']);
        Route::get('saccos/members', [SaccoMembersAPIController::class, 'getMembers'])->middleware('permission:View Sacco Members');
        Route::post('saccos/members/add', [SaccoMembersAPIController::class, 'addMember']);
        // Creates a NEW account inside the caller's own SACCO. 'members/add'
        // only links an existing user; creating one needed the platform-only
        // 'Add Users', which left SACCO admins unable to add their own staff.
        Route::post('saccos/members/create', [SaccoMembersAPIController::class, 'createMember']);
        Route::get('saccos/vehicles', [SaccoVehiclesAPIController::class, 'getSaccoVehicles'])->middleware('permission:View Sacco Vehicles');
        Route::post('saccos/vehicles/add', [SaccoVehiclesAPIController::class, 'addVehicle']);
        Route::get('saccos/routes', [SaccoRoutesAPIController::class, 'getSaccoRoutes'])->middleware('permission:View Sacco Routes');
        // Sacco fares (SACCO-controlled pricing)
        Route::get('saccos/fares', [SaccoFaresAPIController::class, 'getFares'])->middleware('permission:View Fares');
        Route::post('saccos/fares/add', [SaccoFaresAPIController::class, 'addFare'])
            ->middleware('permission:Add Fares');
        Route::post('saccos/fares/delete', [SaccoFaresAPIController::class, 'deleteFare'])
            ->middleware('permission:Edit Fares');
        // Roles & permissions (RBAC — the dashboard renders per-permission)
        // Intentionally unguarded. The controller already returns only
        // Roles::saccoAssignable() to non-superadmins, the payload is role and
        // permission NAMES rather than anyone's data, and every member-managing
        // screen needs it to render a role picker. 'View Roles' is PLATFORM_ONLY
        // and would lock out the SACCO admins this exists for.
        Route::get('roles', [RolesController::class, 'roles']);
        Route::get('permissions', [RolesController::class, 'permissions'])->middleware('permission:View Permissions');
        Route::post('roles/save', [RolesController::class, 'saveRole']);           // superadmin (enforced in controller)
        Route::post('saccos/members/{user}/roles', [RolesController::class, 'assignMemberRoles']);
        // Sacco loyalty program config
        Route::get('saccos/loyalty', [SaccoLoyaltyController::class, 'show'])->middleware('permission:View Loyalty');
        /*
        | Who holds points in this SACCO. READ ONLY, and there is deliberately no
        | write sibling: points are earned server-side from a paid fare and spent
        | by the passenger on their own token. An admin endpoint that could credit
        | a balance would be an admin endpoint that issues free rides.
        */
        Route::get('saccos/loyalty/holders', [LoyaltyHoldersController::class, 'forSacco'])
            ->middleware('permission:View Loyalty');
        Route::post('saccos/loyalty/save', [SaccoLoyaltyController::class, 'save'])
            ->middleware('permission:Edit Loyalty');
        // Sacco billing (read-only: a SACCO sees its own subscription + invoices)
        Route::get('saccos/billing/subscription', [SaccoBillingController::class, 'subscription'])->middleware('permission:View Invoices');
        Route::get('saccos/billing/invoices', [SaccoBillingController::class, 'invoices'])->middleware('permission:View Invoices');
        Route::get('saccos/billing/invoices/{invoice}', [SaccoBillingController::class, 'showInvoice'])->middleware('permission:View Invoices');
        // Billing administration (superadmin: plans, assignment, generation, collection)
        Route::get('billing/plans', [BillingAdminController::class, 'plans'])->middleware('permission:View Billing Plans');
        Route::post('billing/plans/save', [BillingAdminController::class, 'savePlan']);
        Route::post('billing/subscriptions/assign', [BillingAdminController::class, 'assign']);
        Route::get('billing/invoices', [BillingAdminController::class, 'invoices'])->middleware('permission:View Invoices');
        Route::post('billing/invoices/generate', [BillingAdminController::class, 'generate']);
        Route::post('billing/invoices/{invoice}/void', [BillingAdminController::class, 'void']);
        Route::post('billing/invoices/{invoice}/payments', [BillingAdminController::class, 'recordPayment']);
        // dddd
        // Vehicles
        Route::get('vehicles', [VehiclesAPIController::class, 'getVehicles'])->middleware('permission:View Vehicles');
        Route::post('vehicles/add', [VehiclesAPIController::class, 'addVehicle']);
        /*
        | Crew — the SACCO's PEOPLE, and the buses they are on.
        |
        | Distinct from vehicles/users below, which lists ASSIGNMENTS. The crews
        | screen was rendering that table as a directory, so on NICCO's 261
        | assignment rows for 179 people one investor appeared 40 times, seven
        | drivers with no assignment did not appear at all, and every past
        | rotation showed as a separate "Ended" row with no vehicle. These
        | endpoints list people; the assignment is an attribute, and the history
        | is a separate call.
        |
        | Role changes are NOT here. They already have an endpoint that checks a
        | permission ceiling and writes an audit record — POST
        | saccos/members/{user}/roles — and authorization should not grow a
        | second door.
        */
        Route::get('crew', [CrewAPIController::class, 'index'])->middleware('permission:View Vehicle Users');
        Route::post('crew/{id}', [CrewAPIController::class, 'update'])->whereNumber('id');
        Route::post('crew/{id}/assign', [CrewAPIController::class, 'assign'])->whereNumber('id');
        Route::post('crew/{id}/unassign', [CrewAPIController::class, 'unassign'])->whereNumber('id');
        Route::get('crew/{id}/history', [CrewAPIController::class, 'history'])->whereNumber('id');

        Route::get('vehicles/users', [VehicleUsersAPIController::class, 'getVehicleUsers'])->middleware('permission:View Vehicle Users');
        // Crew assignment: the read above existed with no way to change what it
        // reports. Closing an assignment keeps the row (who crewed which bus on
        // a day money was collected) rather than deleting it.
        Route::post('vehicles/users/add', [VehicleUsersAPIController::class, 'addVehicleUser']);
        Route::post('vehicles/users/{id}/end', [VehicleUsersAPIController::class, 'endVehicleUser']);
        // Stand a crew member down without ending the assignment. /end closes it
        // for good; this only flips status, so driver login (which resolves on
        // status = true AND end_date IS NULL) refuses them while the record of
        // who crews which bus survives. Pass suspend=false to restore.
        Route::post('vehicles/users/{id}/suspend', [VehicleUsersAPIController::class, 'suspendVehicleUser']);

        // Suspend or restore a SACCO resource. The API had exactly ONE delete
        // route across its whole surface, so taking a vehicle off the road or
        // standing down a route meant re-submitting the entire record through
        // its "add" endpoint with status = 0 -- undiscoverable, and it
        // overwrote every other field with whatever the form held.
        //
        // Suspension, not deletion, on purpose: a vehicle is referenced by
        // transactions and summaries, a route by queues. Hard deletes either
        // fail on a foreign key or orphan money.
        //
        // resource = vehicles | routes | places | termini | members
        Route::post('sacco/{resource}/{id}/state', [ResourceStateController::class, 'update'])
            ->whereIn('resource', ['vehicles', 'routes', 'places', 'termini', 'members'])
            ->whereNumber('id');

        // A SACCO talking to its own drivers and conductors. Nothing here could
        // do that: every notification the system sent came from a domain event
        // (a paid booking), never from a person, so a SACCO's only channel to
        // its crew was a WhatsApp group.
        //
        // Throttled because it is a mass-push surface -- one endpoint, two
        // hundred phones, and no way to unsend. The permission check is in the
        // controller (not a route guard) so the 403 message names what is
        // missing.
        Route::get('sacco/announcements', [AnnouncementsController::class, 'index']);
        Route::post('sacco/announcements', [AnnouncementsController::class, 'store'])
            ->middleware('throttle:12,1');

        // The NCBA push-notification request letter, held as data so the UI can
        // render and edit it. One per SACCO, because that is how the Daraja
        // credentials work -- vehicles differ only by their own till.
        //
        // `apply` is the human step that puts the bank's issued tills onto the
        // vehicles. It is separate from the partner write-back on purpose: a
        // wrong merchant_short_code does not fail, it succeeds silently and the
        // bus's money simply never arrives (KDY 599G, a month, ~Ksh 20.8k/day).
        Route::get('bank/till-requests', [TillRequestsController::class, 'index']);
        Route::post('bank/till-requests', [TillRequestsController::class, 'store']);
        Route::post('bank/till-requests/{id}', [TillRequestsController::class, 'update'])->whereNumber('id');
        Route::post('bank/till-requests/{id}/apply', [TillRequestsController::class, 'apply'])->whereNumber('id');
        Route::get('vehicles/seat_settings', [SeatsAPIController::class, 'getSeats'])->middleware('permission:View Seat Settings');

        // Bookings
        // NO route guard: the controller BRANCHES on this permission -- without it
        // the caller is narrowed to their own rows, with it they see the SACCO's.
        // A route guard would 403 the passenger path the controller supports.
        Route::get('bookings/passengers', [BookingsAPIController::class, 'getPassengerBookings']);
        Route::get('bookings/passengers/view/{id}', [BookingsAPIController::class, 'getPassengerBooking']);
        // Passenger self-service cancel of an unpaid hold (releases the seat). The
        // controller scopes it to the caller's own booking; staff with Edit
        // Passengers may cancel any, so no route-level permission guard here.
        Route::post('bookings/passengers/cancel/{id}', [BookingsAPIController::class, 'cancelBooking']);
        // Passenger-scoped payment/receipt history — derived from the caller's own
        // paid bookings + M-Pesa receipts (the dashboard transactions list is
        // permission-gated, so a passenger cannot use it).
        Route::get('payments/history', [PassengerPaymentsController::class, 'index']);
        Route::get('bookings/passenger/pick/{id}', [BookingsAPIController::class, 'pickPassenger'])->middleware('permission:Edit Passengers');
        Route::post('bookings/passengers/pick', [BookingsAPIController::class, 'pickPassengers'])->middleware('permission:Edit Passengers');
        Route::get('bookings/parcels', [BookingsAPIController::class, 'getParcels'])->middleware('permission:View Parcels');
        // expense_and_fees
        Route::get('expense_and_fees', [ExpenseAndFeesAPIController::class, 'index'])->middleware('permission:View Expense And Fees');
        Route::post('expense_and_fees/add', [ExpenseAndFeesAPIController::class, 'addVehicleExpenseAndFees']);
        // points
        // NO route guard: the controller BRANCHES on this permission -- without it
        // the caller is narrowed to their own rows, with it they see the SACCO's.
        // A route guard would 403 the passenger path the controller supports.
        Route::get('points', [PointsAPIController::class, 'getPoints']);
        Route::get('redeemed_points', [PointsAPIController::class, 'getRedeemedPoints']);
        // users
        // NOT 'View Users' — that is PLATFORM_ONLY, but this controller already
        // narrows non-superadmins to their own SACCO, so it is a SACCO-scoped
        // list, not the platform-wide one the permission name implies.
        Route::get('users', [UsersAPIController::class, 'getUsers'])->middleware('permission:View Sacco Members');
        Route::get('users/roles', [RoleAPIController::class, 'getRoles'])->middleware('permission:View Roles');
        Route::get('users/roles/view/{id}', [RoleAPIController::class, 'role'])->middleware('permission:View Roles');
        // Both carry PLATFORM_ONLY permissions. `users/roles/add` had NO
        // middleware and no in-method check, so ANY authenticated caller — a
        // passenger, a driver — could create a role or rename an existing one.
        // Renaming is the sharp end: roles are matched by NAME, so renaming a
        // SACCO-tier role to "Super Admin" hands its holders the platform.
        Route::post('users/roles/add', [RoleAPIController::class, 'addRole'])
            ->middleware('permission:Add Roles|Edit Roles');
        Route::post('users/roles/permissions/add', [RoleAPIController::class, 'addPermissions'])
            ->middleware('permission:Add Roles|Edit Roles');

        // settings
        // Reference enumeration (Male/Female/...), used to render sign-up and profile
        // forms. Same reasoning as queue statuses.
        Route::get('settings/gender', [GenderAPIController::class, 'getGenders']);

        // A SACCO's own activity log. audit_logs was readable only through
        // /super, so a SACCO could not see its own history -- including driver
        // sign-ins, which are the record that a vehicle changed hands.
        Route::get('activity', [ActivityLogController::class, 'index'])
            ->middleware('permission:View Activity Log');
        Route::get('settings/expense_and_fees', [ExpenseAndFeesSettingsAPIController::class, 'index'])->middleware('permission:View Expense And Fees Settings');
        Route::post('settings/expense_and_fees/add', [ExpenseAndFeesSettingsAPIController::class, 'addExpenseFee']);

        // Notifications (in-app list/read + push-device registration)
        Route::get('notifications', [NotificationsController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationsController::class, 'unreadCount']);
        Route::post('notifications/read-all', [NotificationsController::class, 'markAllRead']);
        Route::post('notifications/{id}/read', [NotificationsController::class, 'markRead']);
        Route::post('notifications/devices', [DeviceController::class, 'register']);
        Route::delete('notifications/devices/{token}', [DeviceController::class, 'unregister'])->where('token', '.*');

        // profile
        Route::post('profile/edit', [ProfileAPIController::class, 'editProfile']);
        // Passenger-clean profile save (name + phone). The mobile edit screen
        // persists locally only today; this is the remote save it needs, and
        // where a Google passenger adds the phone they signed up without.
        Route::post('profile/update', [ProfileUpdateController::class, 'update']);
        Route::post('profile/change_password', [ProfileAPIController::class, 'changePassword']);
        Route::post('profile/upload_picture', [ProfileAPIController::class, 'uploadProfilePicture']);

        Route::post('user', [AuthController::class, 'user']); // ->middleware(CheckAPIUserStatus::class);
    });
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('refresh', [AuthController::class, 'refresh']);
};

foreach (['auth', 'v1/auth'] as $apiPrefix) {
    Route::middleware('brand')->prefix($apiPrefix)->group($mobileApi);
}

/*
| Bank partner lead list (NCBA / Co-op).
|
| Deliberately OUTSIDE the `brand` middleware: a bank opens a bare shared link
| and sends no X-App-Key, so there is no brand to resolve from the request. The
| partner key itself identifies the bank, and the bank fixes the brand whose
| drivers are readable — see BankPartnerAuth.
|
| Throttled because the key is the only secret: this is the endpoint someone
| would guess against.
*/
Route::prefix('v1/partner/bank')
    ->middleware(['partner.bank', 'throttle:30,1'])
    ->group(function (): void {
        Route::get('leads', [BankLeadsController::class, 'index']);
        Route::get('leads/export', [BankLeadsController::class, 'export']);

        // The other half of the loop. Read-only until now: the bank pulled a
        // lead list and whatever happened next was invisible to us.
        //
        // NEITHER of these writes a vehicle's till. The bank's issued tills are
        // staged on the request and applied by a human from the dashboard --
        // a shared partner key that could redirect a bus's collections is not a
        // trade worth making.
        Route::post('leads/{lead}/account', [BankWriteBackController::class, 'accountOpened'])->whereNumber('lead');
        Route::post('till-requests/{tillRequest}/credentials', [BankWriteBackController::class, 'tillCredentials'])->whereNumber('tillRequest');
    });

/*
| Super-admin platform console (cross-brand).
|
| Auto-loads every routes/super/*.php file so each domain (notifications, money,
| access, fraud, tenant, logs) owns its own route file with no shared-file
| conflicts. Guarded by: brand (sets Context for BelongsToBrand writes + the
| super-admin BrandScope exemption), auth:sanctum, and `super` (403 for
| non-super-admins). Registered under both /api/v1/super and /api/super.
*/
foreach (['v1/super', 'super'] as $superPrefix) {
    Route::middleware(['brand', 'auth:sanctum', 'super'])
        ->prefix($superPrefix)
        ->group(function (): void {
            foreach (glob(base_path('routes/super/*.php')) as $superRoutes) {
                require $superRoutes;
            }
        });
}
