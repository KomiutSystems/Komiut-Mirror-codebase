<?php

declare(strict_types=1);

namespace App\Observers\Team;

use App\Models\MpesaPaymentSetting;
use App\Services\Team\AutoCards\AutoCards;

/**
 * Head-office changes the ops board's head-office cards follow: keys saved
 * or cleared, switched to live or back to the test system, enabled or
 * disabled, added or removed. Saving keys does not re-register the waiting
 * buses -- someone presses Register tills -- so this is what closes the card
 * the moment the head office is ready. See HeadOfficeCards.
 */
final class HeadOfficeCardsObserver
{
    private const WATCHED = ['consumer_key', 'consumer_secret', 'is_live', 'status', 'sacco_id'];

    public function saved(MpesaPaymentSetting $setting): void
    {
        if (! $setting->wasRecentlyCreated && ! $setting->wasChanged(self::WATCHED)) {
            return;
        }

        $cards = app(AutoCards::class);
        $cards->connection((int) $setting->id);
        if ($setting->wasChanged('sacco_id') && $setting->getOriginal('sacco_id') !== null) {
            $cards->headOffice((int) $setting->getOriginal('sacco_id'));
        }
    }

    public function deleted(MpesaPaymentSetting $setting): void
    {
        $cards = app(AutoCards::class);
        // Gone by the time the card work runs: name its SACCO directly.
        $cards->connection((int) $setting->id);
        if ($setting->sacco_id !== null) {
            $cards->headOffice((int) $setting->sacco_id);
        }
    }
}
