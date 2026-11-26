<?php

declare(strict_types=1);

/*
| Safetek team workspace -- the shared inbox.
| Prefix (super/team) and middleware (brand, auth:sanctum, can:team-workspace)
| come from routes/api.php, which loads every routes/team/*.php file once per
| prefix -- so declare NO named routes here.
|
| The two routes AWS and browsers call without a token (the SES/SNS webhook
| and the signed attachment download) are in routes/team-inbox-public.php.
*/

use App\Http\Controllers\APIs\Super\Team\Inbox\TeamInboxController;
use Illuminate\Support\Facades\Route;

Route::get('inbox/mailboxes', [TeamInboxController::class, 'mailboxes']);
Route::get('inbox/threads', [TeamInboxController::class, 'threads']);
Route::get('inbox/threads/{thread}', [TeamInboxController::class, 'show'])->whereNumber('thread');
Route::patch('inbox/threads/{thread}', [TeamInboxController::class, 'update'])->whereNumber('thread');
Route::post('inbox/threads/{thread}/reply', [TeamInboxController::class, 'reply'])->whereNumber('thread');
Route::post('inbox/threads/{thread}/notes', [TeamInboxController::class, 'note'])->whereNumber('thread');
Route::post('inbox/compose', [TeamInboxController::class, 'compose']);
