<?php

declare(strict_types=1);

namespace App\Services\Mpesa;

use App\Models\Vehicle;

/**
 * THE ONE DEFINITION of "which bus does this M-Pesa shortcode belong to".
 *
 * Attribution is by BusinessShortCode against the vehicle's
 * merchant_short_code — the same rule the NCBA and Co-op paths use. It lives
 * here rather than inside C2bConfirmationController because a second reader has
 * appeared: the repair that re-attributes payments recorded before this rule was
 * fixed. Two copies of a rule that decides whose money this is would be exactly
 * the kind of drift that produced the plate-search bug, and the cost of getting
 * it wrong here is money on the wrong bus.
 *
 * withoutGlobalScopes: THIS IS THE FIX FOR THE 41% NULL-VEHICLE RATE. Recording
 * a payment has no authenticated user, so SaccoScope and FinancierScope are
 * already no-ops. BrandScope is not: it keys on Context, which the `brand`
 * middleware sets from the request HOST — and every till in the fleet is
 * registered with Safaricom against the single `config('app.url')` host. So
 * every confirmation, for every brand's buses, arrived under ONE brand, and the
 * scope made the other brand's fleet invisible to this lookup. Measured in
 * production on 2026-08-26: all 54 vehicles on brand `safiri` recorded with
 * vehicle_id NULL — 2,576 transactions, KES 159,947, 40.9% of that day's
 * payments. The money was stored in `mpesas` and never reached a bus.
 *
 * Whose money this is, is decided by the shortcode Safaricom sends. The brand of
 * the host the callback happened to land on is not evidence about that, so it
 * must not narrow the search.
 *
 * A shortcode matching MORE THAN ONE vehicle is unattributable rather than
 * resolved with `->first()`. Production contains such cases — 880100 is shared
 * by 34 vehicles, 331872 by 9, '0' by 2 — and picking the first row there
 * silently credits one arbitrary bus with everyone's money. Dropping the brand
 * filter widens the candidate set, so the guard matters MORE, not less.
 *
 * NO BillRefNumber FALLBACK, deliberately. NCBARestPaymentsController falls back
 * to `till_number` for shortcode 880100 because that paybill is NCBA's
 * aggregator: it identifies the bank, not a bus. That case cannot occur on the
 * per-till URL — of the confirmations delivered there in production, ZERO carry
 * 880100 and ZERO carry a non-empty BillRefNumber, because those registrations
 * are buy-goods tills where the field is blank. A fallback would be unreachable
 * code on a live money path, and till_number carries no uniqueness guarantee, so
 * it would only widen the ambiguity surface for no gain.
 *
 * VALIDATED AGAINST LEGACY. Before the 2026-08-26 re-attribution was run, the 52
 * shortcodes it would touch were resolved through this rule and compared with the
 * bus the legacy Mumbai system had independently credited for the same day:
 * 52 of 52 agreed, 0 disagreed.
 */
final class VehicleByShortCode
{
    public static function resolve(string $shortCode): ?Vehicle
    {
        $shortCode = trim($shortCode);

        if ($shortCode === '') {
            return null;
        }

        // take(2): enough to know whether it is ambiguous, without loading a fleet.
        $matches = Vehicle::withoutGlobalScopes()
            ->where('merchant_short_code', $shortCode)
            ->take(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
