<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Mpesa;
use App\Models\Transaction;
use App\Support\TransDate;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A fare landed on a bus — pushed to its crew over Reverb the moment it does.
 *
 * Until now the driver app learned it had been paid by re-fetching `driver/home`
 * and diffing the list, which is why a payment could sit unseen for as long as
 * the poll interval. The socket server, the nginx upgrade proxy and the Pusher
 * client are already in production carrying the live map, so this is one more
 * event on infrastructure that is already proven.
 *
 * THE PAYLOAD IS DELIBERATELY IDENTICAL to a row from
 * DriverPortalController::recentTransactions, so the app renders a pushed
 * payment and a polled one through the same code. It also means `at` had to be
 * real: this event would have shipped `at: null` alongside the polled list until
 * the trans_date reader was fixed, and a realtime feed of undated payments is no
 * better than the screen it replaces. `payer` is the first name only, matching
 * that endpoint — a manifest does not need a full identity and this leaves the
 * building to a phone.
 *
 * WHY `vehicle.{id}` AND NOT `trip.{queueId}`. Money arrives whether or not
 * anyone started a trip: KCL 942M took six payments with no open queue at all,
 * and VehicleMoved deliberately returns [] when queue_id is null. A payment
 * broadcast on the trip channel would go nowhere in exactly the case people
 * test with. The bus is the thing being paid, so the bus is the channel.
 *
 * PLAIN ShouldBroadcast, exactly like VehicleMoved beside it. An earlier version
 * of this file reached for ShouldBroadcastAfterCommit, which does not exist in
 * this framework version -- Laravel 13 ships ShouldBroadcast and
 * ShouldBroadcastNow and nothing else -- so every dispatch died on a missing
 * interface, silently, because announce() caught it. That is the whole reason
 * the first attempt was reverted after four red runs.
 *
 * After-commit semantics are not needed here in any case: the recorder wraps
 * nothing in an explicit transaction, so there is no commit to wait for. What
 * keeps a payment safe is that announce() catches its own failures and that the
 * polled list, not this event, is the source of truth. The recorder answers
 * Safaricom BEFORE doing its work because a retry storm is worse than a row we
 * can repair, and broadcasting must never become a thing that can slow or fail
 * a confirmation.
 */
class PaymentRecorded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Transaction $transaction,
        public ?Mpesa $mpesa = null,
    ) {}

    public function broadcastOn(): array
    {
        // An unattributed payment belongs to no bus, so no crew can be told
        // about it. It is not lost — reportUnmatchedPayment already raises it —
        // but it has no channel to go to.
        if ($this->transaction->vehicle_id === null) {
            return [];
        }

        return [new PrivateChannel('vehicle.'.$this->transaction->vehicle_id)];
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
            'id' => (int) $this->transaction->id,
            'vehicle_id' => (int) $this->transaction->vehicle_id,
            'amount' => (float) $this->transaction->amount,
            'method' => (int) $this->transaction->mpesa_id > 0 ? 'mpesa' : 'cash',
            'reference' => $this->mpesa?->TransID,
            'payer' => $this->mpesa?->FirstName,
            'at' => TransDate::iso($this->transaction->trans_date),
        ];
    }
}
