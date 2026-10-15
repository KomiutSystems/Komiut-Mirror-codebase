<?php

declare(strict_types=1);

namespace App\Services\Trips;

use App\Models\Queue;

/** What TripEnder did: ended the trip, or refused with a status and a body. */
final class TripEndResult
{
    /** @param  array<string, mixed>  $body */
    private function __construct(
        public readonly ?Queue $queue,
        public readonly int $noShows,
        public readonly int $status,
        public readonly array $body,
    ) {}

    public static function ended(Queue $queue, int $noShows): self
    {
        return new self($queue, $noShows, 200, []);
    }

    /** @param  array<string, mixed>  $body */
    public static function refused(int $status, array $body): self
    {
        return new self(null, 0, $status, $body);
    }

    public function wasEnded(): bool
    {
        return $this->queue !== null;
    }
}
