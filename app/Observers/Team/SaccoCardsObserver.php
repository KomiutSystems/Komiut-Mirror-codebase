<?php

declare(strict_types=1);

namespace App\Observers\Team;

use App\Enums\SaccoClaimStatus;
use App\Models\Sacco;
use App\Services\Team\AutoCards\AutoCards;

/**
 * SACCO changes the ops board's directory-claim cards follow: a SACCO
 * arriving in (or entering) pending review, and every way review ends --
 * claimed, deactivated by a reject or a merge, or deleted. See
 * DirectoryClaimCards. Ids only, and only when claim_status or status moved.
 */
final class SaccoCardsObserver
{
    public function saved(Sacco $sacco): void
    {
        $relevant = $sacco->wasRecentlyCreated
            ? $sacco->claim_status === SaccoClaimStatus::PendingReview
            : $sacco->wasChanged(['claim_status', 'status']);

        if ($relevant) {
            app(AutoCards::class)->directoryClaim((int) $sacco->id);
        }
    }

    public function deleted(Sacco $sacco): void
    {
        $cards = app(AutoCards::class);
        $cards->directoryClaim((int) $sacco->id);
        $cards->headOffice((int) $sacco->id);
    }
}
