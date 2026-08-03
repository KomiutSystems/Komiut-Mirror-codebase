<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The envelope the /super console reads — identical over REST and the Reverb
 * broadcast, so one client model handles both. camelCase to match the frontend
 * notifications.ts contract.
 */
class PlatformNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'severity' => $this->severity,
            'class' => $this->delivery_class,
            'brand' => $this->brand,
            'title' => $this->title,
            'summary' => $this->summary,
            'actor' => $this->actor,
            'subject' => $this->subject,
            'data' => $this->data,
            'count' => (int) $this->count,
            'auditId' => $this->audit_id,
            'isRead' => $this->read_at !== null,
            'isResolved' => $this->resolved_at !== null,
            'firstOccurredAt' => optional($this->first_occurred_at)->toIso8601String(),
            'occurredAt' => optional($this->occurred_at)->toIso8601String(),
        ];
    }
}
