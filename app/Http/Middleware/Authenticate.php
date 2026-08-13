<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Where to send an unauthenticated caller. Nowhere: this service is an API.
     *
     * This used to be `$request->expectsJson() ? null : route('login')`, the
     * stock scaffolding for an app that also serves a login page. There is no
     * such page here -- App\Http\Kernel deliberately defines no `web` group and
     * routes/web.php registers nothing -- so `route('login')` threw
     * RouteNotFoundException and every unauthenticated request WITHOUT an
     * `Accept: application/json` header came back 500 with a stack trace in the
     * log, where 401 belonged.
     *
     * JSON clients were never affected (expectsJson() short-circuited to null),
     * which is why it survived: the mobile app and the dashboard both send the
     * header. A browser address bar, a curl smoke test, a health prober or an
     * uptime monitor did not.
     *
     * Returning null unconditionally makes the parent throw
     * AuthenticationException, which the handler renders as 401 JSON.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
