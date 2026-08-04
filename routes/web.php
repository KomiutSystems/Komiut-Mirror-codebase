<?php
declare(strict_types=1);
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes — intentionally empty
|--------------------------------------------------------------------------
|
| This application is an API only. The Blade dashboard, the marketing pages
| and the Laravel-UI auth scaffolding were removed: the operator dashboard is
| a separate Next.js app, and the passenger/driver clients are the mobile
| apps — all of which consume routes/api.php exclusively.
|
| WARNING — do not add routes to this file.
|
| RouteServiceProvider loads it with Route::middleware('web'), but App\Http\Kernel
| defines NO 'web' group (deliberately: no cookies, sessions or CSRF, because
| nothing here renders HTML). While the file is empty that mismatch is harmless.
| The moment a route exists here, every request to it dies with
| "Target class [web] does not exist" and Laravel returns a 500 — which is easy
| to misread as an application fault rather than a missing middleware group.
|
| The load-balancer health probe lives in routes/api.php at /api/up for exactly
| this reason. API endpoints belong there too.
|
*/
Route::get('/', function () {
    return 'This is an API-only application.';
});