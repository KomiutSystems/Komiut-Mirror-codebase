<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One of the passenger's in-app payments changed status -- pushed to their own
 * phone, on the channel the app already holds open for balance.changed and
 * notifications.
 *
 * A POINTER, NOT THE DATA. The payload names the payment and its new status
 * and nothing else; the app re-reads GET passenger/payments/{id} for the row.
 * One source of truth for what a payment looks like, and nothing on the
 * socket that the REST read would not also give.
 *
 * Dispatched only by PaymentUpdates, which runs after the settlement commits,
 * dedupes on the status the phone was last told, and catches its own
 * failures: a socket server that is down must never reach Safaricom as a
 * failed callback. Plain ShouldBroadcast, like PassengerBalanceChanged: the
 * socket write runs on a queue worker, off the payment path entirely.
 *
 * AN ACCELERANT, as balance.changed is: a frame lost in a tunnel costs the
 * passenger nothing, because the app re-reads the list on resume and polls a
 * pending payment it has heard nothing about.
 */
final class PaymentUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /** The name the mobile client binds to. */
    public const WIRE_NAME = 'payment.updated';

    public function __construct(
        public readonly int $userId,
        public readonly string $paymentId,
        public readonly string $kind,
        public readonly string $status,
        public readonly ?string $updatedAt,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return $this->userId > 0 ? [new PrivateChannel('App.Models.User.'.$this->userId)] : [];
    }

    public function broadcastAs(): string
    {
        return self::WIRE_NAME;
    }

    /** @return array{id: string, kind: string, status: string, updated_at: ?string} */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->paymentId,
            'kind' => $this->kind,
            'status' => $this->status,
            'updated_at' => $this->updatedAt,
        ];
    }
}
