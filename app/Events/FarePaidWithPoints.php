<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\QrcodePayment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A fare on this bus was just paid with points -- pushed to its crew.
 *
 * A points payment is the one fare that leaves no trace at the door: no cash
 * in the conductor's hand, no M-Pesa SMS, no C2B confirmation landing on the
 * till (which is what PaymentRecorded announces). The passenger's receipt
 * screen was the only proof, and a conductor had nothing of their own to
 * check it against. This is that something.
 *
 * SAME CHANNEL, SAME EVENT NAME, SAME SHAPE as PaymentRecorded, on purpose:
 * the crew app already listens on `vehicle.{id}` for `payment.recorded` and
 * renders each one through the takings row. A points fare is a row like any
 * other, with `amount` 0 (no shilling reached the till), `method` "points",
 * and two extra keys -- `fare`, the KES the ride was worth, and
 * `points_spent` -- so the row can read "KES 70 · 23.3 pts".
 */
class FarePaidWithPoints implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public QrcodePayment $payment,
        public float $fare,
        public float $pointsSpent,
        public ?string $payer,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('vehicle.'.$this->payment->vehicle_id)];
    }

    public function broadcastAs(): string
    {
        return 'payment.recorded';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => (int) $this->payment->id,
            'vehicle_id' => (int) $this->payment->vehicle_id,
            'amount' => 0.0,
            'method' => 'points',
            'fare' => $this->fare,
            'points_spent' => $this->pointsSpent,
            'reference' => 'QR-PTS-'.$this->payment->id,
            'payer' => $this->payer,
            'at' => optional($this->payment->created_at)->toIso8601String(),
        ];
    }
}
