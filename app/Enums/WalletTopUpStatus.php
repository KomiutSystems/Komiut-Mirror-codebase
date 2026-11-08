<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The life of one top-up attempt.
 *
 * The backing values are the app's own `TopUpStatus`, which falls back to
 * `failed` for anything it does not recognise and treats everything except
 * `pending` as final (`isFinal`). So a new state added here without adding it
 * there would read on the passenger's phone as a failed top-up -- the worst
 * possible default for one that actually succeeded.
 */
enum WalletTopUpStatus: string
{
    /** Asked for, not yet answered. The only non-final state. */
    case Pending = 'pending';

    /** Money arrived and the wallet was credited. */
    case Completed = 'completed';

    /** The rail said no, or the payer's PIN was wrong. */
    case Failed = 'failed';

    /** The payer dismissed the prompt. */
    case Cancelled = 'cancelled';

    /**
     * Nobody ever answered. NOT the same as failed: a timed-out top-up may
     * still be credited later if the money turns up, which is why the
     * reconciliation sweep keeps looking at these.
     */
    case TimedOut = 'timed_out';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    /** Whether money may still arrive for this attempt. */
    public function mayStillSettle(): bool
    {
        return $this === self::Pending || $this === self::TimedOut;
    }
}
