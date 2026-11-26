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
use Illuminate\Support\Carbon;
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

    public const FIRST_REPLY = 'first_reply';

    /** In board order. */
    public const KEYS = [self::SACCOS_LIVE, self::TILLS_REGISTERED, self::COLLECTED, self::FIRST_REPLY];

    /** @var array<string, array{unit: string, better: string}> */
    public const DEFINITIONS = [
        self::SACCOS_LIVE => ['unit' => 'count', 'better' => 'up'],
        self::TILLS_REGISTERED => ['unit' => 'percent', 'better' => 'up'],
        self::COLLECTED => ['unit' => 'kes', 'better' => 'up'],
        self::FIRST_REPLY => ['unit' => 'hours', 'better' => 'down'],
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
            self::FIRST_REPLY => 'Support first reply',
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
     * as TillRegistrar::isOurs, so a bus counts the same here as on its own
     * screen even before the registrar has looked at it:
     *   - its bank delivers it: NCBA's paybill, a bank collection account on
     *     record (coop_till / ncba_till), or the status already says not_ours
     *     or bank_issued;
     *   - no head office could take it: no head office of its own and no live
     *     one in its SACCO.
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
        $notOurs = [TillRegistrar::NOT_OURS, TillRegistrar::BANK_ISSUED];

        $row = DB::table('vehicles as v')
            ->where(fn (Builder $q) => $q->whereRaw(self::code('v.merchant_short_code')." <> ''")
                ->orWhereRaw(self::code('v.till_number')." <> ''"))
            ->whereRaw(self::code('v.merchant_short_code').' <> ?', [app(NcbaStkClient::class)->paybill()])
            ->whereRaw("coalesce(trim(v.coop_till), '') = ''")
            ->whereRaw("coalesce(trim(v.ncba_till), '') = ''")
            ->where(fn (Builder $q) => $q->whereNull('v.till_registration_status')
                ->orWhereNotIn('v.till_registration_status', $notOurs))
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
            self::FIRST_REPLY => ['value' => $this->firstReply($period), 'previous' => $this->firstReply($previous)],
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
     * Median hours from a thread's first inbound message to our first reply,
     * over the threads that first wrote to us in the period. A thread nobody
     * has answered yet is left out rather than counted as zero or as "so far";
     * null when no thread in the period has a reply.
     */
    private function firstReply(KpiPeriod $period): ?float
    {
        $answered = DB::table('team_threads')
            ->where('first_inbound_at', '>=', $period->startsAt())
            ->where('first_inbound_at', '<', $period->endsAt())
            ->whereNotNull('first_reply_at')
            ->whereColumn('first_reply_at', '>=', 'first_inbound_at');

        if (DB::connection()->getDriverName() === 'pgsql') {
            $seconds = (clone $answered)
                ->selectRaw('percentile_cont(0.5) within group (order by extract(epoch from (first_reply_at - first_inbound_at))) as median')
                ->value('median');

            return $seconds === null ? null : round((float) $seconds / 3600, 2);
        }

        // Other drivers (none in production) have no percentile: the median by
        // hand over the period's answered threads, a support inbox's worth.
        $waits = $answered->get(['first_inbound_at', 'first_reply_at'])
            ->map(fn ($t) => Carbon::parse($t->first_reply_at)->diffInSeconds(Carbon::parse($t->first_inbound_at), true))
            ->sort()->values();

        $n = $waits->count();
        if ($n === 0) {
            return null;
        }
        $median = $n % 2 === 1 ? $waits[intdiv($n, 2)] : ($waits[$n / 2 - 1] + $waits[$n / 2]) / 2;

        return round($median / 3600, 2);
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
