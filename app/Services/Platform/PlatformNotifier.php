<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Events\PlatformNotificationCreated;
use App\Models\PlatformNotification;

/**
 * The single choke point for every super-admin console event. Emitters build a
 * PlatformEvent value and hand it here; dedup/throttle, the audit link, and the
 * Reverb broadcast all live in one place so the feed never diverges.
 *
 * Dedup/throttle: if an OPEN (unresolved) notification with the same dedupe_key
 * exists inside the event's window, its count is incremented and occurred_at
 * refreshed — no new row. A read row is re-flagged unread on a fresh occurrence.
 * Pass windowMinutes = 0 (or a never-throttle event) to always create a row.
 */
class PlatformNotifier
{
    public function dispatch(PlatformEvent $event): PlatformNotification
    {
        $now = now();

        if ($event->dedupeKey !== null && $event->windowMinutes > 0) {
            $existing = PlatformNotification::query()
                ->where('dedupe_key', $event->dedupeKey)
                ->whereNull('resolved_at')
                ->where('occurred_at', '>=', $now->copy()->subMinutes($event->windowMinutes))
                ->latest('occurred_at')
                ->first();

            if ($existing !== null) {
                $existing->forceFill([
                    'count' => $existing->count + 1,
                    'occurred_at' => $now,
                    'read_at' => null,            // a fresh occurrence re-surfaces it
                    'title' => $event->title,     // keep the latest rendered copy
                    'summary' => $event->summary,
                    'data' => $event->data,
                ])->save();

                PlatformNotificationCreated::dispatch($existing);

                return $existing;
            }
        }

        $notification = PlatformNotification::create([
            'event' => $event->event,
            'severity' => $event->severity,
            'delivery_class' => $event->class,
            'brand' => $event->brand,
            'title' => $event->title,
            'summary' => mb_substr($event->summary, 0, 300),
            'actor' => $event->actor,
            'subject' => $event->subject,
            'data' => $event->data,
            'dedupe_key' => $event->dedupeKey,
            'count' => 1,
            'audit_id' => $event->auditId,
            'first_occurred_at' => $event->occurredAt ?? $now,
            'occurred_at' => $event->occurredAt ?? $now,
        ]);

        PlatformNotificationCreated::dispatch($notification);

        return $notification;
    }
}
