<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Enums\WalletTopUpRail;
use App\Services\Ncba\NcbaStkClient;

/**
 * The gate on every rail that can put money into a wallet.
 *
 * THIS IS A SECURITY BOUNDARY, NOT A FEATURE FLAG, and the distinction is the
 * reason the class exists on its own rather than as a config() call sprinkled
 * through the ingest.
 *
 * Every M-Pesa credential in this system belongs to a SACCO:
 * `mpesa_payment_settings` is resolved booking -> vehicle -> SACCO, and NCBA's
 * 880100 tills are per-bus. None of them is Komiut's. So if a wallet could be
 * credited by a payment that landed on one of those, a passenger could type
 * their wallet account number into ANY bus's paybill and be credited with that
 * SACCO's money -- the platform would be issuing float against someone else's
 * collections.
 *
 * Hence: a wallet is credited only by money that arrived on the shortcode
 * configured here, and when nothing is configured NOTHING credits. Unset is the
 * shipped state, exactly as services.ncba_stk is, and it fails closed.
 *
 * What provisioning this actually needs:
 *   mpesa  a Komiut paybill from Safaricom plus a Daraja app over it
 *          (WALLET_FLOAT_SHORT_CODE / _CONSUMER_KEY / _CONSUMER_SECRET /
 *          _PASS_KEY)
 *   ncba   an account-number scheme on 880100 that credits the Komiut float
 *          account rather than a SACCO collection account -- an open question
 *          with the bank, since NcbaStkClient is collection-only and has no
 *          transfer or balance call at all
 */
final class WalletFloat
{
    public function __construct(private NcbaStkClient $ncba) {}

    /** The Komiut-owned shortcode the float collects on, or null. */
    public function shortCode(): ?string
    {
        $code = trim((string) config('wallet.float.short_code'));

        return $code === '' ? null : $code;
    }

    /**
     * Whether money that arrived on this shortcode may credit a wallet.
     *
     * Compared as a trimmed string: shortcodes arrive from Safaricom as digits
     * in a JSON field and from config as an env string, and `'880100' == 880100`
     * being true in PHP is not something to lean on when the answer decides
     * whose money becomes whose float.
     */
    public function ownsShortCode(?string $businessShortCode): bool
    {
        $ours = $this->shortCode();

        if ($ours === null) {
            return false;
        }

        $theirs = trim((string) $businessShortCode);

        return $theirs !== '' && hash_equals($ours, $theirs);
    }

    /** Whether a rail can be offered to a passenger at all. */
    public function railIsLive(WalletTopUpRail $rail): bool
    {
        if ($this->shortCode() === null) {
            return false;
        }

        return match ($rail) {
            // Daraja needs its own three credentials over the Komiut paybill.
            WalletTopUpRail::Mpesa => filled(config('wallet.float.consumer_key'))
                && filled(config('wallet.float.consumer_secret'))
                && filled(config('wallet.float.pass_key')),

            /**
             * HARD OFF, and not merely unconfigured.
             *
             * NcbaStkClient::initiate pushes on PayBillNo 880100, so the money
             * arrives with BusinessShortCode 880100 -- NCBA's AGGREGATOR
             * paybill, shared by every SACCO's NCBA till. That cannot be gated:
             *
             *   float.short_code = a Komiut Daraja paybill -> ownsShortCode()
             *       rejects the 880100 confirmation, so the passenger pays and
             *       is credited nothing;
             *   float.short_code = 880100 -> the security gate is GONE, because
             *       every NCBA till's fare money now also "arrived on the float
             *       shortcode".
             *
             * One shortcode cannot gate both rails. So this stays false until
             * NCBA answers whether an account-number scheme on 880100 can
             * credit the Komiut float account rather than a SACCO collection
             * account -- at which point the gate becomes (shortcode AND
             * account-number scheme), not shortcode alone.
             *
             * Deliberately NOT keyed on $this->ncba->enabled(): provisioning
             * NCBA_STK for the fare pilot would otherwise switch this rail on
             * as a side effect and start losing passengers' money.
             */
            WalletTopUpRail::Ncba => false,
        };
    }

    /**
     * The rails to advertise on GET /wallet, in the app's shape.
     *
     * The config list says which rails EXIST; this says which can actually take
     * money today. A source advertised as available with no float account
     * behind it is a Top Up button that charges a passenger and credits
     * nothing, so availability is computed, never taken from config alone.
     *
     * @return array<int, array{id: string, available: bool}>
     */
    public function sources(): array
    {
        $configured = (array) config('wallet.top_up_sources', []);
        $sources = [];

        foreach ($configured as $entry) {
            $rail = WalletTopUpRail::tryParse($entry['id'] ?? null);

            if ($rail === null) {
                continue;
            }

            $sources[] = [
                'id' => $rail->value,
                // Both halves must hold: switched on in config AND actually
                // provisioned. Either alone is a lie to the passenger.
                'available' => (bool) ($entry['available'] ?? false) && $this->railIsLive($rail),
            ];
        }

        return $sources;
    }

    /** Whether any rail at all can take money right now. */
    public function anyRailIsLive(): bool
    {
        foreach ($this->sources() as $source) {
            if ($source['available']) {
                return true;
            }
        }

        return false;
    }
}
