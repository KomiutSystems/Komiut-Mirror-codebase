<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Team\CardEventsBackfill;
use Illuminate\Console\Command;

/**
 * Add the board moves team_card_events is missing (CardEventsBackfill): run
 * once after a deploy that changed how moves are recorded has rolled, so moves
 * made by the previous build in between are counted. Safe to run any time.
 */
class TeamSquareCardEvents extends Command
{
    protected $signature = 'team:square-card-events';

    protected $description = 'Add the board moves team_card_events is missing, from the audit trail and the cards';

    public function handle(CardEventsBackfill $backfill): int
    {
        $this->info('Events added: '.$backfill->run());

        return self::SUCCESS;
    }
}
