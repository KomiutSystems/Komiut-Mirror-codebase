<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Team\AutoCards\AutoCards;
use Illuminate\Console\Command;

/**
 * Bring the ops board's automatic cards in line with the platform as it is
 * now: open a card for every problem that should have one and does not,
 * close every card whose problem has gone.
 *
 * The cards are raised by events (AutoCards), and events get missed: a
 * deploy mid-request, a listener that failed and was only reported, a row
 * fixed by hand in the database, a flag resolved before this shipped. This
 * sweep is what makes a missed event cost at most an hour. It reads only
 * indexed, bounded sets -- buses in a failing till state (a partial index),
 * the directory review queue, open fraud flags, and the board's own open
 * cards -- never a big table, and it is idempotent: run twice, the second
 * run changes nothing. It never reopens a card a person moved to Done.
 */
class TeamSyncAutoCards extends Command
{
    protected $signature = 'team:sync-auto-cards';

    protected $description = 'Open missing and close resolved automatic cards on the team ops board';

    public function handle(AutoCards $cards): int
    {
        $failed = false;

        foreach ($cards->sweep() as $source => $counts) {
            $this->line(sprintf('%-18s opened %d, closed %d%s', $source, $counts['opened'], $counts['closed'], ($counts['failed'] ?? false) ? ' (FAILED, reported)' : ''));
            $failed = $failed || ($counts['failed'] ?? false);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
