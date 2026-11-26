<?php

declare(strict_types=1);

/*
| The team inbox's two routes that are called WITHOUT a bearer token, so they
| sit outside the team-workspace gate (routes/team/inbox.php has the rest).
| Required once from routes/api.php (prefix "api"), so naming a route here is
| safe -- unlike routes/team/*.php, which is loaded once per prefix.
|
| - ses: AWS SNS delivering SES-received mail. Proven genuine by SNS's
|   signature and the topic allowlist, 404 while inbound is switched off.
|   Its own per-IP limit replaces the api group's: SNS delivers from a pool
|   of AWS addresses, and an email is one request.
| - attachments: the console's download links. A browser cannot send the
|   token, so the URL's signature (minutes long, see InboxPresenter) is the
|   permission. Signed over the path only, so the load balancer's scheme
|   and host do not invalidate it.
*/

use App\Http\Controllers\APIs\Super\Team\Inbox\InboxAttachmentController;
use App\Http\Controllers\APIs\Super\Team\Inbox\InboxSesController;
use App\Services\Team\Inbox\InboxPresenter;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

Route::post('v1/team-inbox/ses', InboxSesController::class)
    ->withoutMiddleware(ThrottleRequests::class.':api')
    ->middleware('throttle:120,1');

Route::get('v1/team-inbox/attachments/{attachment}', InboxAttachmentController::class)
    ->whereNumber('attachment')
    ->middleware('signed:relative')
    ->name(InboxPresenter::DOWNLOAD_ROUTE);
