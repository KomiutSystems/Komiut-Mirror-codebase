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
 * A fare paid from a passenger's wallet, pushed to the bus's crew.
 *
 * WHY THE CREW NEEDS TELLING AT ALL. A wallet tap leaves nothing at the door --
 * no cash, no M-Pesa SMS, no till confirmation -- so without this the conductor
 * has only the passenger's word and a phone screen that could be last week's
 * receipt. FarePaidWithPoints exists for exactly this reason and this is its
 * twin; the only difference is which ledger the money came out of.
 *
 * `payment.recorded` ON `vehicle.{id}`, the same wire name and channel as
 * PaymentRecorded and FarePaidWithPoints, because the crew app renders every
 * fare through one takings row. A third event name would mean a third code path
 * on the handset for a row that looks the same.
 *
 * `amount` IS 0.0 and `fare` carries the figure, matching the points row for
 * the same honest reason: no shilling reached the SACCO's till. Wallet money
 * sits in the Komiut float until it is settled, so a KES figure in the takings
 * `amount` would tell the conductor cash arrived that did not.
 *
 * `method` is "wallet" rather than "points", which is the whole point of
 * qrcode_payments.payment_method existing -- otherwise the two are
 * indistinguishable on the receipt and on this row.
 */
class FarePaidFromWallet implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public QrcodePayment $payment,
        public float $fare,
        public ?string $payer,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('vehicle.'.$this->payment->vehicle_id)];
    }

    public function broadcastAs(): string
    {
        return 'payment.recorded';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return self::row($this->payment, $this->fare, $this->payer);
    }

    /**
     * One takings row for a wallet fare.
     *
     * `id` is the STRING "qr-wlt-{receipt id}", never the bare id, for the
     * reason FarePaidWithPoints records: the M-Pesa and cash rows carry
     * transactions.id while this carries qrcode_payments.id, the two tables'
     * numbers overlap, and the crew app dedups pushes by id -- so a bare id
     * could silently drop a fare whose receipt id matched a transaction already
     * on screen. It must also differ from the points prefix, or a points fare
     * and a wallet fare with the same receipt id would collide with each other.
     *
     * @return array<string, mixed>
     */
    public static function row(QrcodePayment $payment, ?float $fare, ?string $payer): array
    {
        return [
            'id' => 'qr-wlt-'.$payment->id,
            'source' => 'qrcode_payment',
            'vehicle_id' => (int) $payment->vehicle_id,
            'amount' => 0.0,
            'method' => 'wallet',
            'fare' => $fare,
            'points_spent' => null,
            'reference' => 'QR-WLT-'.$payment->id,
            'payer' => $payer,
            'at' => optional($payment->created_at)->toIso8601String(),
        ];
    }
}
