<?php

declare(strict_types=1);

namespace App\Observers\Team;

use App\Models\PlatformNotification;
use App\Services\Team\AutoCards\AutoCards;
use App\Services\Team\AutoCards\FraudCards;

/**
 * Console flags the ops board's fraud cards follow: a fraud flag raised, or
 * resolved by a save. (The console's own Resolve button is a query update,
 * heard through PlatformNotificationResolved instead.) A repeat that only
 * ticks an open flag's count is ignored -- its card is already there.
 * Every other event in the feed returns on the first comparison.
 */
final class FraudCardsObserver
{
    public function saved(PlatformNotification $flag): void
    {
        if (! FraudCards::watches($flag->event)) {
            return;
        }

        if ($flag->wasRecentlyCreated) {
            app(AutoCards::class)->fraud((int) $flag->id, new: true);
        } elseif ($flag->wasChanged('resolved_at')) {
            app(AutoCards::class)->fraud((int) $flag->id);
        }
    }
}
