<?php

/*
| Super-admin notification centre — the core feed (backbone-owned).
| Group prefix + middleware (brand, auth:sanctum, super) come from routes/api.php.
| Additional per-domain routes live in sibling routes/super/*.php files.
*/

use App\Http\Controllers\APIs\Super\PlatformNotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:View Platform Notifications')->group(function (): void {
    Route::get('notifications', [PlatformNotificationController::class, 'index']);
    Route::get('notifications/unread-count', [PlatformNotificationController::class, 'unreadCount']);
    Route::post('notifications/read-all', [PlatformNotificationController::class, 'markAllRead']);
    Route::post('notifications/{id}/read', [PlatformNotificationController::class, 'markRead']);
    Route::post('notifications/{id}/resolve', [PlatformNotificationController::class, 'resolve']);
});
