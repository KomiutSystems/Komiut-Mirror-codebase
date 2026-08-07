<?php

declare(strict_types=1);

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
| The file itself stays because RouteServiceProvider loads it by path. Add web
| routes here only if this service ever needs to render HTML again; API
| endpoints belong in routes/api.php.
|
*/

use Illuminate\Support\Facades\Route;

/*
 * Load-balancer health check.
 *
 * Every route in routes/api.php sits behind ResolveBrand, which resolves the
 * brand from the Host header or X-App-Key and FAILS CLOSED (404) when neither
 * matches. An ALB health check arrives with Host set to the target's private IP
 * and no app key, so it can never satisfy that — which is why probing an API
 * path leaves healthy instances marked unhealthy and gets them replaced.
 *
 * DELIBERATELY SHALLOW: this reports "the PHP process is up and routing", and
 * does NOT touch the database or Redis. A dependency check here would be
 * actively harmful — one database blip would fail the check on EVERY instance
 * at once, the ASG would replace all of them, and a brief outage becomes a
 * total one. Depth belongs in CloudWatch alarms (RDS/Redis have their own
 * metrics) and in the platform health command, not in the probe that decides
 * whether to terminate servers.
 */
Route::get('/up', fn () => response()->json(['status' => 'ok'], 200));
