<?php

declare(strict_types=1);

namespace App\Services\Super\Money;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Is every shilling Safaricom put in a till also in our database?
 *
 * Every C2B confirmation carries OrgAccountBalance: the till's balance
 * immediately after that payment. So for consecutive confirmations on one till,
 *
 *     balance(n) - balance(n-1) - amount(n) == 0
 *
 * and any positive residue is money that entered the till without reaching us.
 * This is the only completeness check available that does not consult the legacy
 * system — which matters, because legacy is being switched off, and because
 * legacy turns out not to be complete either: on 2026-08-30 comparing the two
 * systems said KES 8,745 was missing, while this ledger said KES 20,890.
 *
 * VALIDATED. For KDX 439C on 2026-08-31 the residues between 06:37 and 07:44
 * summed to exactly KES 850.00 — the same figure, to the shilling, as the 16
 * named receipts legacy holds and we do not.
 *
 * ---------------------------------------------------------------------------
 * WHY THE RESIDUES ARE NETTED, not summed as positives.
 *
 * The naive reading — add up every positive residue — overstates. Production
 * shows adjacent pairs like +29.92 then -60.00 then +30.00 within seconds,
 * netting to -0.08: a charge or a reversal settling against the till, not eleven
 * lost fares. Summing positives called that KES 59.92 of loss; netting calls it
 * nothing, which is right. Netting also absorbs the case where Safaricom orders
 * two same-second payments differently than their timestamps.
 *
 * The cost of netting is that a real loss and a real refund in the same day
 * cancel. That is the safer direction to be wrong in: this figure raises an
 * alarm, and an alarm that cries wolf is worth less than one that occasionally
 * under-reports.
 *
 * ---------------------------------------------------------------------------
 * WHY LARGE JUMPS ARE REPORTED SEPARATELY.
 *
 * Fares on this fleet run 20 to 200. A single residue in the thousands is far
 * more likely a bank transfer, a B2C credit or a cash deposit into the till than
 * a burst of missed fares — KDX 439C shows exactly one, KES 1,750 across a
 * 55-minute window with no confirmations either side. Folding that into "lost
 * fares" would triple the headline and be wrong. It is surfaced, not silenced,
 * because it is still money we cannot explain.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS CANNOT SEE.
 *
 * A gap needs a confirmation on both sides of it to be visible. If a till's
 * FIRST payment of the window is the one that went missing there is nothing to
 * subtract from, so the window is seeded with the last confirmation BEFORE it —
 * making the only blind spot a till whose very first payment ever was lost.
 * A row with no stored balance breaks the chain rather than being compared
 * across, because comparing across it would invent a residue.
 */
final class TillLedgerAudit
{
    /**
     * A residue above this is reported as an unexplained CREDIT, not a lost fare.
     * Fares on this fleet are 20-200; the largest legitimate single fare seen is
     * an order of magnitude below this.
     */
    private const LARGE_CREDIT = 500.0;

    /** Ignore floating-point dust from decimal arithmetic. */
    private const EPSILON = 0.009;

    /**
     * @param  list<string>|null  $shortCodes  null = every till with traffic
     * @return array{
     *     lost_fares: float,
     *     large_credits: float,
     *     large_credit_count: int,
     *     confirmations: int,
     *     tills_checked: int,
     *     tills_affected: int,
     *     unchecked_rows: int,
     *     per_till: array<string, array{lost: float, credits: float, confirmations: int}>
     * }
     */
    public function audit(CarbonInterface $from, CarbonInterface $to, ?array $shortCodes = null): array
    {
        $rows = $this->rows($from, $to, $shortCodes);

        $perTill = [];
        $previous = [];        // shortcode => last balance seen
        $confirmations = 0;
        $uncheckable = 0;
        $largeCredits = 0.0;
        $largeCount = 0;

        foreach ($rows as $row) {
            $till = (string) $row->shortcode;
            $perTill[$till] ??= ['lost' => 0.0, 'credits' => 0.0, 'confirmations' => 0];

            // Rows outside the window seed the chain; they are context, not data.
            $inWindow = $row->in_window;
            if ($inWindow) {
                $confirmations++;
                $perTill[$till]['confirmations']++;
            }

            if ($row->balance === null) {
                // No balance recorded: the chain cannot continue across this row.
                unset($previous[$till]);
                if ($inWindow) {
                    $uncheckable++;
                }

                continue;
            }

            $balance = (float) $row->balance;

            if (isset($previous[$till]) && $inWindow) {
                $residue = round($balance - ($previous[$till] + (float) $row->amount), 2);

                if ($residue > self::LARGE_CREDIT) {
                    $largeCredits += $residue;
                    $largeCount++;
                    $perTill[$till]['credits'] += $residue;
                } elseif (abs($residue) > self::EPSILON) {
                    // Netted, so a charge/reversal pair cancels itself out.
                    $perTill[$till]['lost'] += $residue;
                }
            }

            $previous[$till] = $balance;
        }

        $lost = 0.0;
        $affected = 0;
        foreach ($perTill as $till => $t) {
            if ($t['lost'] > self::EPSILON) {
                $lost += $t['lost'];
                $affected++;
            } else {
                // A negative net is a refund or a charge, not recovered money;
                // it must not be allowed to offset another till's loss.
                $perTill[$till]['lost'] = max(0.0, $t['lost']);
            }
        }

        uasort($perTill, fn ($a, $b) => $b['lost'] <=> $a['lost']);

        return [
            'lost_fares' => round($lost, 2),
            'large_credits' => round($largeCredits, 2),
            'large_credit_count' => $largeCount,
            'confirmations' => $confirmations,
            'tills_checked' => count($perTill),
            'tills_affected' => $affected,
            'unchecked_rows' => $uncheckable,
            'per_till' => $perTill,
        ];
    }

    /**
     * The window, plus one row before it per till to seed the chain.
     *
     * Ordered by till then time so the walk above is a single pass. `id` breaks
     * ties within a second so the order is at least deterministic between runs.
     */
    private function rows(CarbonInterface $from, CarbonInterface $to, ?array $shortCodes)
    {
        $select = 'm."BusinessShortCode" as shortcode, m."TransTime" as at, m."OrgAccountBalance" as balance,
                   m."TransAmount" as amount, m.id';

        $window = DB::table('mpesas as m')
            ->selectRaw($select.', true as in_window')
            ->where('m.TransTime', '>=', $from)
            ->where('m.TransTime', '<', $to)
            ->whereNotNull('m.BusinessShortCode')
            ->where('m.BusinessShortCode', '!=', '')
            ->when($shortCodes !== null, fn ($q) => $q->whereIn('m.BusinessShortCode', $shortCodes));

        // One seed per till: the newest confirmation before the window.
        $seed = DB::table('mpesas as m')
            ->selectRaw($select.', false as in_window')
            ->whereIn('m.id', function ($q) use ($from, $shortCodes): void {
                $q->selectRaw('max(id)')
                    ->from('mpesas')
                    ->where('TransTime', '<', $from)
                    ->where('TransTime', '>=', $from->copy()->subDay())
                    ->whereNotNull('BusinessShortCode')
                    ->where('BusinessShortCode', '!=', '')
                    ->when($shortCodes !== null, fn ($qq) => $qq->whereIn('BusinessShortCode', $shortCodes))
                    ->groupBy('BusinessShortCode');
            });

        return $window->union($seed)->orderBy('shortcode')->orderBy('at')->orderBy('id')->get();
    }
}
