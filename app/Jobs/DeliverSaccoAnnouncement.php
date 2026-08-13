<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Models\SaccoAnnouncement;
use App\Models\SaccoUser;
use App\Models\User;
use App\Models\VehicleUser;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Fans one announcement out to a SACCO's crew.
 *
 * Queued rather than done in the request: a SACCO with two hundred drivers
 * means two hundred NotificationService calls, each enqueueing in-app +
 * realtime + push work. Doing that inline would hold the admin's HTTP request
 * open for the whole fan-out and time out the ones that matter most — the big
 * SACCOs.
 *
 * Recipients are resolved from SACCO MEMBERSHIP, not from who is currently on a
 * bus. "No service tomorrow" is exactly the message a driver who is off shift
 * today needs, and keying on an open vehicle_users row would silently drop
 * them. Targeting one vehicle narrows to that bus's crew instead.
 *
 * Legacy conductors were migrated as UserType::Driver, so filtering on type
 * covers both halves of a crew; there is no separate conductor type to miss.
 */
class DeliverSaccoAnnouncement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $announcementId) {}

    public function handle(NotificationService $notifications): void
    {
        // Without the scopes: a queue worker runs with no authenticated user, so
        // SaccoScope would resolve to "no SACCO" and find nothing.
        $announcement = SaccoAnnouncement::withoutGlobalScopes()->find($this->announcementId);
        if ($announcement === null) {
            return;
        }

        $recipients = $this->recipients($announcement);

        foreach ($recipients as $crew) {
            $notifications->dispatch(
                $crew,
                NotificationType::System,
                $announcement->title,
                $announcement->body,
                // The announcement's own id. NotificationService skips its
                // dedupe entirely when this is null, so a retried job would push
                // the same notice to every driver a second time.
                (string) $announcement->id,
                (string) $announcement->sacco_id,
            );
        }

        $announcement->forceFill(['recipients' => $recipients->count()])->save();
    }

    /**
     * The crew this announcement is for.
     *
     * @return Collection<int, User>
     */
    private function recipients(SaccoAnnouncement $announcement): Collection
    {
        if ($announcement->vehicle_id !== null) {
            $ids = VehicleUser::where('vehicle_id', $announcement->vehicle_id)
                ->where('status', true)
                ->whereNull('end_date')
                ->pluck('user_id');
        } else {
            // Both sides of membership: `users.sacco_id` is the home SACCO, and
            // `sacco_users` is the join table the dashboard maintains. A driver
            // added through one path but not the other must still be reachable.
            $ids = User::withoutGlobalScopes()
                ->where('sacco_id', $announcement->sacco_id)
                ->pluck('id')
                ->merge(
                    SaccoUser::where('sacco_id', $announcement->sacco_id)
                        ->where('status', true)
                        ->pluck('user_id')
                );
        }

        return User::withoutGlobalScopes()
            ->whereIn('id', $ids->unique()->values())
            ->where('type', UserType::Driver)
            ->where('status', true)
            ->get();
    }
}
