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
| A minimal 'web' middleware group now exists in App\Http\Kernel, so a route here
| works — it was previously absent, and any route in this file died with
| "Target class [web] does not exist".
|
| Keep this file to a plain landing response. API endpoints belong in
| routes/api.php, and so does the load-balancer health probe (/api/up) — it must
| stay out of the brand-scoped groups, which fail closed on an unknown Host.
|
*/
Route::get('/', function () {
    return 'This is an API-only application.';
});