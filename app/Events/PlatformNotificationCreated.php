<?php

declare(strict_types=1);

namespace App\Events;

use App\Http\Resources\PlatformNotificationResource;
use App\Models\PlatformNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to the single cross-brand `private-super` channel whenever a platform
 * notification is created or its count ticks. Carries the same envelope the REST
 * feed returns, so the console patches its cache in the background — no refetch.
 */
class PlatformNotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public PlatformNotification $notification) {}

    public function broadcastOn(): PrivateChannel
    {
        // Base name 'super' → the client subscribes on 'private-super'.
        return new PrivateChannel(config('platform.channel', 'super'));
    }

    public function broadcastAs(): string
    {
        return 'platform.notification';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return PlatformNotificationResource::make($this->notification)->resolve();
    }
}
