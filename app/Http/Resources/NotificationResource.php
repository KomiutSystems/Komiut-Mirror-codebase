<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * A stored notification in the exact camelCase shape the app's
 * NotificationModel.fromJson reads — so migrating the app is a path change, not
 * a model change. `type`/`title`/`message`/`referenceId` come from the stored
 * data payload; `isRead`/`createdAt`/`id` from the row.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->data ?? [];

        return [
            'id' => $this->id,
            'title' => $data['title'] ?? 'Notification',
            'message' => $data['message'] ?? '',
            'type' => $data['type'] ?? 'system',
            'referenceId' => $data['referenceId'] ?? null,
            'organizationId' => $data['organizationId'] ?? null,
            'isRead' => $this->read_at !== null,
            'createdAt' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
