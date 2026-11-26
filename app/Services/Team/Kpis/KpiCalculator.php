<?php

declare(strict_types=1);

namespace App\Services\Team\Kpis;

use App\Models\Team\TeamKpiSnapshot;
use App\Models\Team\TeamKpiTarget;
use App\Services\Mpesa\TillRegistrar;
use App\Services\Ncba\NcbaStkClient;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The ops board's KPI strip: four numbers about the whole platform, both
 * brands together, for the week or month so far against the whole previous
 * one.
 *
 * Every figure is one aggregate over an indexed range, never a walk of the
 * money tables, and the computed set is cached for a few minutes: a handful of
 * people open the board all day, and the numbers move on the scale of hours.
 * Targets are not cached -- a target someone just saved shows at once.
 *
 * Read with plain DB queries: the workspace spans every SACCO of both brands,
 * so none of the tenant scopes the models carry may apply, and the snapshot
 * command runs with no user at all.
 */
final class KpiCalculator
{
    public const SACCOS_LIVE = 'saccos_live';

    public const TILLS_REGISTERED = 'tills_registered';

    public const COLLECTED = 'collected';

    /** In board order. */
    public const KEYS = [self::SACCOS_LIVE, self::TILLS_REGISTERED, self::COLLECTED];

    /** @var array<string, array{unit: string, better: string}> */
    public const DEFINITIONS = [
        self::SACCOS_LIVE => ['unit' => 'count', 'better' => 'up'],
        self::TILLS_REGISTERED => ['unit' => 'percent', 'better' => 'up'],
        self::COLLECTED => ['unit' => 'kes', 'better' => 'up'],
    ];

    /** How long a computed set is reused. Long enough to spare the database, short enough to feel live. */
    public const CACHE_SECONDS = 300;

    /**
     * @return array{period: array{key: string, from: string, to: string}, kpis: list<array{key: string, label: string, unit: string, value: int|float|null, previous: int|float|null, target: float|null, better: string}>}
     */
    public function report(string $periodKey, ?CarbonInterface $at = null): array
    {
        $period = KpiPeriod::current($periodKey, $at);

        $figures = Cache::remember(
            'team:kpis:'.$period->key.':'.$period->fromDate(),
            self::CACHE_SECONDS,
            fn (): array => $this->figures($period),
        );

        $targets = TeamKpiTarget::query()->where('period', $period->key)->pluck('target', 'key');

        $kpis = [];
        foreach (self::KEYS as $key) {
            $kpis[] = [
                'key' => $key,
                'label' => self::label($key, $period->key),
                'unit' => self::DEFINITIONS[$key]['unit'],
                'value' => $figures[$key]['value'],
                'previous' => $figures[$key]['previous'],
                'target' => isset($targets[$key]) ? (float) $targets[$key] : null,
                'better' => self::DEFINITIONS[$key]['better'],
            ];
        }

        return ['period' => $period->toArray(), 'kpis' => $kpis];
    }

    public static function label(string $key, string $periodKey): string
    {
        return match ($key) {
            self::SACCOS_LIVE => 'SACCOs live',
            self::TILLS_REGISTERED => 'Tills registered',
            self::COLLECTED => 'Collected this '.$periodKey,
        };
    }

    /**
     * Record today's share of tills registered, so a later period has a
     * "previous" for it. Idempotent: a second run on the same business day
     * overwrites that day's row.
     */
    public function snapshot(?CarbonInterface $at = null): ?TeamKpiSnapshot
    {
        $value = $this->tillsRegistered();
        if ($value === null) {
            return null;
        }

        return TeamKpiSnapshot::query()->updateOrCreate(
            ['key' => self::TILLS_REGISTERED, 'date' => BusinessDay::current($at)->toDateString()],
            ['value' => $value],
        );
    }

    /**
     * Tills registered, as a percentage (0-100) of the tills that are ours to
     * register. Null when there are none.
     *
     * WHICH BUSES. A bus "has a till" when it carries a store number
     * (merchant_short_code, what the registrar registers) or a till number --
     * a till with no store number is one we cannot register yet, and that is
     * exactly the kind of gap this number is for.
     *
     * WHICH ARE LEFT OUT. Tills that are not ours to register, by the same rule
     * as TillRegistrar::isOurs, worked out from the bus as it is now so it
     * counts the same here as on its own screen even before the registrar has
     * looked at it:
     *   - its bank delivers it (bankDelivers): NCBA's paybill, a bank
     *     collection account on record (coop_till / ncba_till), or a till the
     *     registrar found to be the bank's own (bank_issued) that is still the
     *     one on the bus;
     *   - no head office could take it: no head office of its own and no live
     *     one in its SACCO.
     * A stored not_ours is NOT trusted on its own: it is written when the bus
     * is saved and nothing rewrites it when the SACCO later connects a live
     * head office, so the bus would drop out of the measure at exactly the
     * moment it became ours to chase. The live rule above already covers every
     * bank reason not_ours is written for. Likewise bank_issued only holds
     * while the store number is still the bank's till number.
     *
     * WHAT COUNTS AS REGISTERED. Only `registered`. Every other status is the
     * team's to chase and stays in the denominator: the failures (refused,
     * bad_keys, no_keys, not_live, gave_up, needs_head_office,
     * confirm_head_office, no_short_code), the ones in flight (pending,
     * unreachable), already_registered (Safaricom holds an older registration
     * and nothing proves its payments reach us) and blank (never tried).
     */
    public function tillsRegistered(): ?float
    {
        $row = DB::table('vehicles as v')
            ->where(fn (Builder $q) => $q->whereRaw(self::code('v.merchant_short_code')." <> ''")
                ->orWhereRaw(self::code('v.till_number')." <> ''"))
            ->whereRaw(self::code('v.merchant_short_code').' <> ?', [app(NcbaStkClient::class)->paybill()])
            ->whereRaw("coalesce(trim(v.coop_till), '') = ''")
            ->whereRaw("coalesce(trim(v.ncba_till), '') = ''")
            ->whereRaw("not (coalesce(v.till_registration_status, '') = ? and ".self::code('v.merchant_short_code').' = '.self::code('v.till_number').')', [TillRegistrar::BANK_ISSUED])
            ->where(fn (Builder $q) => $q
                ->whereExists(fn (Builder $s) => $s->selectRaw('1')->from('mpesa_payment_settings as own')
                    ->whereColumn('own.id', 'v.mpesa_payment_setting_id'))
                ->orWhereExists(fn (Builder $s) => $s->selectRaw('1')->from('mpesa_payment_settings as live')
                    ->whereColumn('live.sacco_id', 'v.sacco_id')
                    ->where('live.is_live', true)
                    ->where('live.status', true)))
            ->selectRaw('count(*) as tills')
            ->selectRaw('coalesce(sum(case when v.till_registration_status = ? then 1 else 0 end), 0) as registered', [TillRegistrar::REGISTERED])
            ->first();

        $tills = (int) ($row->tills ?? 0);

        return $tills === 0 ? null : round(100 * (int) $row->registered / $tills, 1);
    }

    /** @return array<string, array{value: int|float|null, previous: int|float|null}> */
    private function figures(KpiPeriod $period): array
    {
        $previous = $period->previous();
        $now = $this->takings($period);
        $before = $this->takings($previous);

        return [
            self::SACCOS_LIVE => ['value' => $now['saccos'], 'previous' => $before['saccos']],
            self::TILLS_REGISTERED => ['value' => $this->tillsRegistered(), 'previous' => $this->levelAtEndOf($previous, self::TILLS_REGISTERED)],
            self::COLLECTED => ['value' => $now['collected'], 'previous' => $before['collected']],
        ];
    }

    /**
     * SACCOs live and money collected, in one pass over `summaries`.
     *
     * `summaries` is the per-bus daily rollup every M-Pesa, cash and wallet
     * fare is folded into as it lands (C2bPaymentRecorder, and recomputed from
     * `transactions` by app:generate-vehicle-summaries), filed under the
     * payment's Nairobi date. A month is a few days x the buses that worked,
     * read through summaries_trans_date_index -- never the transactions or
     * mpesas tables themselves.
     *
     * SACCO live = at least one bus of it took a fare through us (M-Pesa or
     * wallet) in the period. Cash is left out: a fare the conductor typed in
     * is not a trip paid on the platform. A bus is counted under the SACCO it
     * belongs to now.
     *
     * Collected = mpesa_amount: M-Pesa fares matched to a bus. A payment no bus
     * has been matched to yet is not in it until the orphan sweep attributes
     * it -- the same figure the SACCOs see on their own screens.
     *
     * @return array{saccos: int, collected: float}
     */
    private function takings(KpiPeriod $period): array
    {
        $row = DB::table('summaries as s')
            ->leftJoin('vehicles as v', 'v.id', '=', 's.vehicle_id')
            ->whereBetween('s.trans_date', [$period->fromDate(), $period->toDate()])
            ->selectRaw('count(distinct case when s.mpesa_txn > 0 or s.wallet_txn > 0 then v.sacco_id end) as saccos')
            ->selectRaw('coalesce(sum(s.mpesa_amount), 0) as collected')
            ->first();

        return ['saccos' => (int) ($row->saccos ?? 0), 'collected' => round((float) ($row->collected ?? 0), 2)];
    }

    /**
     * A level KPI has no "previous period" in the live data, only in the
     * nightly snapshots: the one on the period's last day, or failing that
     * (a night the command did not run) the latest one inside the period.
     */
    private function levelAtEndOf(KpiPeriod $period, string $key): ?float
    {
        $value = TeamKpiSnapshot::query()
            ->where('key', $key)
            ->whereBetween('date', [$period->fromDate(), $period->toDate()])
            ->orderByDesc('date')
            ->value('value');

        return $value === null ? null : (float) $value;
    }

    /**
     * A till or store number as the registrar reads it (TillRegistrar::shortCode):
     * no spaces, no leading zeros, and "0" or blank is no number at all.
     */
    private static function code(string $column): string
    {
        return "coalesce(ltrim(replace({$column}, ' ', ''), '0'), '')";
    }
}
