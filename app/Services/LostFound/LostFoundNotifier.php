<?php

declare(strict_types=1);

namespace App\Services\LostFound;

use App\Enums\NotificationType;
use App\Models\LostItemReport;
use App\Models\User;
use App\Models\VehicleUser;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;

/**
 * The pushes a lost-item report sends, and the one rule that governs them all:
 * the crew never learn who reported it, and the passenger never learns which
 * crew answered. Only the report and the bus cross between them.
 */
class LostFoundNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Tell whoever is crewing the bus right now. Returns false when nobody is,
     * so the caller can leave crew_notified_at null and try again at sign-in.
     */
    public function notifyCrew(LostItemReport $report): bool
    {
        $crew = $this->crewOn((int) $report->vehicle_id);

        if ($crew->isEmpty()) {
            return false;
        }

        $plate = $report->vehicle?->plate ?? '';
        $when = $report->trip_date?->isToday()
            ? (string) __('notifications.when.today')
            : (string) __('notifications.when.on', ['date' => $report->trip_date?->toDateString()]);

        foreach ($crew as $member) {
            $this->notifications->dispatch(
                $member,
                NotificationType::LostItem,
                (string) __('notifications.lost_item.crew.title', ['plate' => $plate]),
                (string) __('notifications.lost_item.crew.body', [
                    'category' => $this->category($report->category),
                    'description' => $report->description,
                    'when' => $when,
                ]),
                referenceId: $report->publicId(),
                titleKey: 'notifications.lost_item.crew.title',
                messageKey: 'notifications.lost_item.crew.body',
                params: [
                    'plate' => $plate,
                    'category' => $this->category($report->category),
                    'description' => $report->description,
                    'when' => $when,
                ],
            );
        }

        return true;
    }

    /** The crew answered: tell the passenger what they said. */
    public function notifyPassenger(LostItemReport $report): void
    {
        $passenger = $report->user;

        if ($passenger === null) {
            return;
        }

        $category = $this->category($report->category);
        $plate = $report->vehicle?->plate ?? '';

        [$titleKey, $bodyKey, $params] = $report->found_at !== null
            ? [
                'notifications.lost_item.found.title',
                'notifications.lost_item.found.body',
                [
                    'category' => $category,
                    'place' => $report->found_place_name ?: $plate,
                    'reference' => $report->reference(),
                ],
            ]
            : [
                'notifications.lost_item.not_found.title',
                'notifications.lost_item.not_found.body',
                ['category' => $category, 'plate' => $plate],
            ];

        $this->notifications->dispatch(
            $passenger,
            NotificationType::LostItem,
            (string) __($titleKey, $params),
            // The crew's own note is more use than a generic sentence, so it
            // wins when they left one.
            $report->found_note ?: (string) __($bodyKey, $params),
            referenceId: $report->publicId(),
            titleKey: $titleKey,
            messageKey: $report->found_note ? null : $bodyKey,
            params: $params,
        );
    }

    /**
     * Reports filed while nobody was signed in. Called from the crew sign-in
     * path, which must never fail because of this.
     */
    public function notifyPendingFor(int $vehicleId): void
    {
        LostItemReport::where('vehicle_id', $vehicleId)
            ->where('status', 'open')
            ->whereNull('crew_notified_at')
            ->with('vehicle')
            ->get()
            ->each(function (LostItemReport $report): void {
                if ($this->notifyCrew($report)) {
                    $report->forceFill(['crew_notified_at' => Carbon::now()])->save();
                }
            });
    }

    /** Whoever holds an open assignment on this bus. */
    private function crewOn(int $vehicleId)
    {
        $ids = VehicleUser::withoutGlobalScopes()
            ->where('vehicle_id', $vehicleId)
            ->whereNull('end_date')
            ->where('status', true)
            ->pluck('user_id');

        return User::whereIn('id', $ids)->get();
    }

    private function category(string $category): string
    {
        return (string) __('notifications.category.'.$category);
    }
}
