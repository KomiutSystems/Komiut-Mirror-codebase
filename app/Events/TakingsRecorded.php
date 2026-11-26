<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Transaction;
use App\Support\TransDate;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A fare landed on one of a SACCO's buses: tells its web dashboard to refresh
 * "Collected today" now. See SaccoLiveTakings.
 *
 * NO PERSON IN THE PAYLOAD -- no payer name, phone or receipt. The office's
 * dashboard reads those from the polled lists, behind their own permissions;
 * this only says how much, on which bus, and by what means.
 */
class TakingsRecorded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Transaction $transaction,
        public int $saccoId,
        public string $plate,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('sacco.'.$this->saccoId)];
    }

    public function broadcastAs(): string
    {
        return 'takings.recorded';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $t = $this->transaction;

        return [
            'transaction_id' => (int) $t->id,
            'vehicle_id' => (int) $t->vehicle_id,
            'plate' => $this->plate,
            'amount' => (float) $t->amount,
            'method' => self::method($t),
            'recorded_at' => TransDate::iso($t->trans_date),
        ];
    }

    public static function method(Transaction $t): string
    {
        return match (true) {
            (int) $t->wallet_transaction_id > 0 => 'wallet',
            (int) $t->mpesa_id > 0 => 'mpesa',
            (int) $t->cash_id > 0 => 'cash',
            (float) $t->points > 0 => 'points',
            default => 'other',
        };
    }
}
