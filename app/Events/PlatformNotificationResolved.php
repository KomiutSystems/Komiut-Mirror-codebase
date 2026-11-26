<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone resolved a console notification (PlatformNotificationController).
 *
 * Resolving is a guarded query update -- only a row still open is touched --
 * so it raises no model event; this is how anything that follows a flag hears
 * that it was dealt with.
 */
final class PlatformNotificationResolved
{
    use Dispatchable;

    public function __construct(public readonly int $notificationId) {}
}
