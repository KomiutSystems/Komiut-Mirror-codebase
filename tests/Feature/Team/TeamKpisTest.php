<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\MpesaPaymentSetting;
use App\Models\Sacco;
use App\Models\Seat;
use App\Models\Team\TeamKpiSnapshot;
use App\Models\Team\TeamKpiTarget;
use App\Models\Team\TeamMailbox;
use App\Models\Team\TeamThread;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Mpesa\TillRegistrar;
use App\Services\Team\Kpis\KpiCalculator;
use App\Services\Team\Kpis\KpiPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The ops board's KPI strip: SACCOs live, tills registered, money collected
 * and support's first reply, for the week or month so far against the whole
 * previous one, with the team's targets.
 *
 * "Now" is pinned to Wednesday 25 November 2026, midday in Nairobi: the week is
 * 23-29 November, the previous week 16-22, the month November, the previous
 * month October.
 */
final class TeamKpisTest extends QueueTestCase
{
    private const API = '/api/v1/super/team/';

    private ?User $owner = null;

    private ?Seat $seat = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-25 12:00', 'Africa/Nairobi'));
        // A bus saved with a till asks Safaricom; nothing here may reach it.
        Http::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function teamMember(): User
    {
        $user = $this->makeUser([]);
        $user->forceFill(['type' => UserType::Superadmin->value])->save();

        return $user->fresh();
    }

    /** @param  array<string, mixed>  $columns  written straight to the row, past the till observer */
    private function bus(Sacco $sacco, array $columns = []): Vehicle
    {
        $this->owner ??= $this->makeUser([], $sacco);
        $this->seat ??= $this->makeSeat();
        $bus = $this->makeVehicle($sacco, $this->owner, $this->seat);
        if ($columns !== []) {
            DB::table('vehicles')->where('id', $bus->id)->update($columns);
        }

        return $bus;
    }

    private function headOffice(Sacco $sacco, bool $live = true): MpesaPaymentSetting
    {
        return MpesaPaymentSetting::create([
            'sacco_id' => $sacco->id, 'consumer_key' => 'ck', 'consumer_secret' => 'cs',
            'business_short_code' => '5495094', 'pass_key' => 'pk', 'payment_mode' => 'CustomerBuyGoodsOnline',
            'is_live' => $live, 'status' => true,
        ]);
    }

    private function day(Vehicle $bus, string $date, float $mpesa = 0, int $mpesaTxn = 0, int $cashTxn = 0, int $walletTxn = 0): void
    {
        DB::table('summaries')->insert([
            'vehicle_id' => $bus->id, 'trans_date' => $date,
            'mpesa_amount' => $mpesa, 'mpesa_txn' => $mpesaTxn,
            'cash_amount' => $cashTxn * 100, 'cash_txn' => $cashTxn,
            'wallet_amount' => $walletTxn * 100, 'wallet_txn' => $walletTxn,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function thread(string $inboundNairobi, ?float $replyAfterHours): TeamThread
    {
        $mailbox = TeamMailbox::query()->firstOrCreate(['address' => 'support@example.test'], ['name' => 'Support']);
        $inbound = Carbon::parse($inboundNairobi, 'Africa/Nairobi')->utc();

        return TeamThread::create([
            'mailbox_id' => $mailbox->id, 'subject' => 'Help', 'status' => 'open',
            'first_inbound_at' => $inbound,
            'first_reply_at' => $replyAfterHours === null ? null : $inbound->copy()->addSeconds((int) round($replyAfterHours * 3600)),
        ]);
    }

    /** @return array<string, array<string, mixed>> the KPIs by key */
    private function kpis(string $period = 'week'): array
    {
        return collect(app(KpiCalculator::class)->report($period)['kpis'])->keyBy('key')->all();
    }

    #[Test]
    public function only_the_team_may_read_the_kpis_or_set_targets(): void
    {
        $this->getJson(self::API.'kpis')->assertUnauthorized();

        Sanctum::actingAs($this->makeUser([], $this->makeSacco()));
        $this->getJson(self::API.'kpis?period=week')->assertForbidden();
        $this->putJson(self::API.'kpis/saccos_live/target', ['target' => 10])->assertForbidden();
        $this->assertSame(0, TeamKpiTarget::query()->count());
    }

    #[Test]
    public function the_strip_is_shaped_as_the_console_reads_it(): void
    {
        Sanctum::actingAs($this->teamMember());

        $week = $this->getJson(self::API.'kpis?period=week')->assertOk();
        $week->assertJsonPath('period', ['key' => 'week', 'from' => '2026-11-23', 'to' => '2026-11-29']);
        $this->assertSame(['saccos_live', 'tills_registered', 'collected', 'first_reply'], array_column($week->json('kpis'), 'key'));
        $this->assertSame(['SACCOs live', 'Tills registered', 'Collected this week', 'Support first reply'], array_column($week->json('kpis'), 'label'));
        $this->assertSame(['count', 'percent', 'kes', 'hours'], array_column($week->json('kpis'), 'unit'));
        $this->assertSame(['up', 'up', 'up', 'down'], array_column($week->json('kpis'), 'better'));
        foreach ($week->json('kpis') as $kpi) {
            $this->assertSame(['key', 'label', 'unit', 'value', 'previous', 'target', 'better'], array_keys($kpi));
            $this->assertNull($kpi['target']);
        }

        $month = $this->getJson(self::API.'kpis?period=month')->assertOk();
        $month->assertJsonPath('period', ['key' => 'month', 'from' => '2026-11-01', 'to' => '2026-11-30']);
        $month->assertJsonPath('kpis.2.label', 'Collected this month');

        // The board's default view is the week.
        $this->getJson(self::API.'kpis')->assertOk()->assertJsonPath('period.key', 'week');
        $this->getJson(self::API.'kpis?period=year')->assertUnprocessable();
    }

    #[Test]
    public function periods_follow_the_nairobi_business_day(): void
    {
        // 01:00 on Monday 30 November in Nairobi is still Sunday's business
        // day: the night's last runs belong to the week they started in.
        $lateSunday = KpiPeriod::current('week', Carbon::parse('2026-11-30 01:00', 'Africa/Nairobi'));
        $this->assertSame(['key' => 'week', 'from' => '2026-11-23', 'to' => '2026-11-29'], $lateSunday->toArray());

        $monday = KpiPeriod::current('week', Carbon::parse('2026-11-30 03:00', 'Africa/Nairobi'));
        $this->assertSame(['key' => 'week', 'from' => '2026-11-30', 'to' => '2026-12-06'], $monday->toArray());

        // 22:00 UTC on the 30th is 01:00 on 1 December in Nairobi: still
        // November's business day until 03:00.
        $this->assertSame('2026-11-01', KpiPeriod::current('month', Carbon::parse('2026-11-30 22:00', 'UTC'))->fromDate());
        $this->assertSame('2026-12-01', KpiPeriod::current('month', Carbon::parse('2026-12-01 00:00', 'UTC'))->fromDate());

        $week = KpiPeriod::current('week');
        $this->assertSame(['key' => 'week', 'from' => '2026-11-16', 'to' => '2026-11-22'], $week->previous()->toArray());
        // Timestamps are bounded at 03:00 EAT, i.e. 00:00 UTC.
        $this->assertSame('2026-11-23 00:00:00', $week->startsAt()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-30 00:00:00', $week->endsAt()->format('Y-m-d H:i:s'));

        $march = KpiPeriod::current('month', Carbon::parse('2027-03-15 12:00', 'Africa/Nairobi'));
        $this->assertSame(['key' => 'month', 'from' => '2027-02-01', 'to' => '2027-02-28'], $march->previous()->toArray());
    }

    #[Test]
    public function saccos_live_counts_saccos_that_took_a_fare_through_us(): void
    {
        $mpesa = $this->makeSacco();
        $wallet = $this->makeSacco();
        $cashOnly = $this->makeSacco();
        $lastWeek = $this->makeSacco();

        // Two buses of one SACCO are still one SACCO.
        $this->day($this->bus($mpesa), '2026-11-23', 300, 3);
        $this->day($this->bus($mpesa), '2026-11-25', 100, 1);
        $this->day($this->bus($wallet), '2026-11-24', 0, 0, 0, 2);
        // A fare the conductor typed in is not a trip paid on the platform.
        $this->day($this->bus($cashOnly), '2026-11-24', 0, 0, 5);
        // Sunday of last week, and the Monday before it.
        $this->day($this->bus($lastWeek), '2026-11-22', 50, 1);
        $this->day($this->bus($mpesa), '2026-11-16', 70, 1);
        // Outside both weeks.
        $this->day($this->bus($lastWeek), '2026-11-15', 999, 9);

        $kpis = $this->kpis('week');
        $this->assertSame(2, $kpis['saccos_live']['value']);
        $this->assertSame(2, $kpis['saccos_live']['previous']);

        $month = $this->kpis('month');
        $this->assertSame(3, $month['saccos_live']['value'], 'mpesa, wallet and last week\'s SACCO, all in November');
        $this->assertSame(0, $month['saccos_live']['previous']);
    }

    #[Test]
    public function collected_is_the_m_pesa_taken_in_the_period(): void
    {
        $sacco = $this->makeSacco();
        $other = $this->makeSacco();
        $this->day($this->bus($sacco), '2026-11-23', 1200.5, 4, 3);
        $this->day($this->bus($other), '2026-11-29', 800, 2, 0, 1);
        $this->day($this->bus($sacco), '2026-11-22', 300, 1);
        $this->day($this->bus($other), '2026-10-31', 5000, 10);

        $week = $this->kpis('week');
        $this->assertEqualsWithDelta(2000.5, $week['collected']['value'], 0.001, 'cash and wallet fares are not M-Pesa');
        $this->assertEqualsWithDelta(300, $week['collected']['previous'], 0.001);

        $month = $this->kpis('month');
        $this->assertEqualsWithDelta(2300.5, $month['collected']['value'], 0.001);
        $this->assertEqualsWithDelta(5000, $month['collected']['previous'], 0.001);
    }

    #[Test]
    public function tills_registered_is_the_share_of_our_tills_that_are_registered(): void
    {
        $ours = $this->makeSacco();
        $this->headOffice($ours);

        // In the measure: 2 registered of 6.
        $this->bus($ours, ['merchant_short_code' => '1417368', 'till_registration_status' => TillRegistrar::REGISTERED]);
        $this->bus($ours, ['merchant_short_code' => '01417369', 'till_registration_status' => TillRegistrar::REGISTERED]);
        $this->bus($ours, ['merchant_short_code' => '1417370', 'till_registration_status' => TillRegistrar::REFUSED]);
        $this->bus($ours, ['merchant_short_code' => '1417371', 'till_registration_status' => TillRegistrar::ALREADY_REGISTERED]);
        $this->bus($ours, ['merchant_short_code' => '1417372']); // never tried
        $this->bus($ours, ['till_number' => '1885147', 'till_registration_status' => TillRegistrar::NO_SHORT_CODE]);

        // Not ours to register, so out of the measure entirely.
        $this->bus($ours, ['merchant_short_code' => '1417373', 'till_registration_status' => TillRegistrar::BANK_ISSUED]);
        $this->bus($ours, ['merchant_short_code' => '1417374', 'till_registration_status' => TillRegistrar::NOT_OURS]);
        $this->bus($ours, ['merchant_short_code' => '1417375', 'coop_till' => '01100000000001']);
        $this->bus($ours, ['merchant_short_code' => '880100', 'till_number' => '880100']);
        // No till at all.
        $this->bus($ours, ['merchant_short_code' => '0', 'till_number' => ' ']);
        $this->bus($ours);

        // A SACCO with only a test-system head office: its blank tills are
        // the bank's, not ours -- unless a bus has a head office of its own.
        $banked = $this->makeSacco();
        $testOnly = $this->headOffice($banked, live: false);
        $this->bus($banked, ['merchant_short_code' => '2000001']);
        $this->bus($banked, ['merchant_short_code' => '2000002', 'mpesa_payment_setting_id' => $testOnly->id, 'till_registration_status' => TillRegistrar::NOT_LIVE]);

        // 2 registered of 7.
        $this->assertSame(28.6, app(KpiCalculator::class)->tillsRegistered());
        $this->assertSame(28.6, $this->kpis()['tills_registered']['value']);
    }

    #[Test]
    public function tills_registered_is_null_when_there_are_no_tills_to_measure(): void
    {
        $this->bus($this->makeSacco());

        $this->assertNull($this->kpis()['tills_registered']['value']);
        $this->assertNull(app(KpiCalculator::class)->snapshot(), 'nothing to snapshot');
        $this->assertSame(0, TeamKpiSnapshot::query()->count());
    }

    #[Test]
    public function the_previous_share_of_tills_is_the_snapshot_at_the_end_of_the_previous_period(): void
    {
        $sacco = $this->makeSacco();
        $this->headOffice($sacco);
        $this->bus($sacco, ['merchant_short_code' => '1417368', 'till_registration_status' => TillRegistrar::REGISTERED]);

        // No snapshot yet: no trend.
        $this->assertNull($this->kpis()['tills_registered']['previous']);
        Cache::flush();

        TeamKpiSnapshot::create(['key' => 'tills_registered', 'date' => '2026-11-20', 'value' => 40]);
        TeamKpiSnapshot::create(['key' => 'tills_registered', 'date' => '2026-11-22', 'value' => 55.5]);
        TeamKpiSnapshot::create(['key' => 'tills_registered', 'date' => '2026-11-24', 'value' => 90]);
        TeamKpiSnapshot::create(['key' => 'tills_registered', 'date' => '2026-10-30', 'value' => 12]);

        $week = $this->kpis('week');
        $this->assertSame(100.0, $week['tills_registered']['value']);
        $this->assertSame(55.5, $week['tills_registered']['previous'], 'last Sunday night, not this week\'s');

        // A month whose last night was missed falls back to its latest snapshot.
        $this->assertSame(12.0, $this->kpis('month')['tills_registered']['previous']);
    }

    #[Test]
    public function the_nightly_snapshot_is_one_row_per_business_day(): void
    {
        $sacco = $this->makeSacco();
        $this->headOffice($sacco);
        $this->bus($sacco, ['merchant_short_code' => '1417368', 'till_registration_status' => TillRegistrar::REGISTERED]);
        $failing = $this->bus($sacco, ['merchant_short_code' => '1417369', 'till_registration_status' => TillRegistrar::GAVE_UP]);

        Carbon::setTestNow(Carbon::parse('2026-11-25 23:50', 'Africa/Nairobi'));
        $this->artisan('team:snapshot-kpis')->assertSuccessful();
        $this->assertSame([['date' => '2026-11-25', 'value' => 50.0]], $this->snapshots());

        // Run again the same night after a fix: the day's row is replaced.
        DB::table('vehicles')->where('id', $failing->id)->update(['till_registration_status' => TillRegistrar::REGISTERED]);
        $this->artisan('team:snapshot-kpis')->assertSuccessful();
        $this->assertSame([['date' => '2026-11-25', 'value' => 100.0]], $this->snapshots());

        // 01:00 the next morning is still the 25th's business day.
        Carbon::setTestNow(Carbon::parse('2026-11-26 01:00', 'Africa/Nairobi'));
        $this->artisan('team:snapshot-kpis')->assertSuccessful();
        $this->assertCount(1, $this->snapshots());

        Carbon::setTestNow(Carbon::parse('2026-11-26 23:50', 'Africa/Nairobi'));
        $this->artisan('team:snapshot-kpis')->assertSuccessful();
        $this->assertSame(['2026-11-25', '2026-11-26'], array_column($this->snapshots(), 'date'));
    }

    /** @return list<array{date: string, value: float}> */
    private function snapshots(): array
    {
        return TeamKpiSnapshot::query()->where('key', 'tills_registered')->orderBy('date')->get()
            ->map(fn (TeamKpiSnapshot $s) => ['date' => $s->date->toDateString(), 'value' => $s->value])->all();
    }

    #[Test]
    public function first_reply_is_the_median_wait_over_threads_opened_in_the_period(): void
    {
        $this->thread('2026-11-23 09:00', 1);
        $this->thread('2026-11-24 09:00', 4);
        $this->thread('2026-11-25 08:00', 2);
        $this->thread('2026-11-25 10:00', null); // unanswered: left out, not zero
        // 02:00 Monday is still Sunday's business day: last week.
        $this->thread('2026-11-23 02:00', 3);
        $this->thread('2026-11-18 09:00', 0.5);
        // Before last week.
        $this->thread('2026-11-16 02:59', 100);

        $week = $this->kpis('week');
        $this->assertEqualsWithDelta(2.0, $week['first_reply']['value'], 0.001);
        $this->assertEqualsWithDelta(1.75, $week['first_reply']['previous'], 0.001, 'median of 0.5 and 3');
    }

    #[Test]
    public function first_reply_is_null_without_an_answered_thread(): void
    {
        $this->thread('2026-11-24 09:00', null);

        $kpis = $this->kpis('week');
        $this->assertNull($kpis['first_reply']['value']);
        $this->assertNull($kpis['first_reply']['previous']);
    }

    #[Test]
    public function a_target_is_set_per_kpi_per_period_shared_and_audited(): void
    {
        $me = $this->teamMember();
        $colleague = $this->teamMember();
        Sanctum::actingAs($me);

        $this->putJson(self::API.'kpis/saccos_live/target', ['target' => 50])->assertOk()
            ->assertJsonPath('target', ['key' => 'saccos_live', 'period' => 'week', 'target' => 50]);
        $this->putJson(self::API.'kpis/collected/target', ['target' => 2500000.5, 'period' => 'month'])->assertOk();
        $this->putJson(self::API.'kpis/first_reply/target?period=week', ['target' => 4])->assertOk();

        // Shared: a colleague sees the team's targets.
        Sanctum::actingAs($colleague);
        $week = collect($this->getJson(self::API.'kpis?period=week')->assertOk()->json('kpis'))->keyBy('key');
        $this->assertEquals(50, $week['saccos_live']['target']);
        $this->assertEquals(4, $week['first_reply']['target']);
        $this->assertNull($week['collected']['target'], 'the month\'s target is not the week\'s');
        $month = collect($this->getJson(self::API.'kpis?period=month')->assertOk()->json('kpis'))->keyBy('key');
        $this->assertEquals(2500000.5, $month['collected']['target']);
        $this->assertNull($month['saccos_live']['target']);

        // Changed and cleared: still one row per KPI per period.
        $this->putJson(self::API.'kpis/saccos_live/target', ['target' => 60])->assertOk();
        $this->putJson(self::API.'kpis/saccos_live/target', ['target' => null])->assertOk()->assertJsonPath('target.target', null);
        $this->assertSame(1, TeamKpiTarget::query()->where('key', 'saccos_live')->count());
        $row = TeamKpiTarget::query()->where('key', 'saccos_live')->where('period', 'week')->first();
        $this->assertNull($row->target);
        $this->assertSame($colleague->id, $row->updated_by);

        $audits = AuditLog::query()->where('action', 'team.kpi_target.set')->where('subject_id', 'saccos_live:week')->orderBy('id')->get();
        $this->assertCount(3, $audits);
        $this->assertEquals(['key' => 'saccos_live', 'period' => 'week', 'from' => null, 'to' => 50], $audits[0]->data);
        $this->assertEquals(['key' => 'saccos_live', 'period' => 'week', 'from' => 50, 'to' => 60], $audits[1]->data);
        $this->assertEquals(['key' => 'saccos_live', 'period' => 'week', 'from' => 60, 'to' => null], $audits[2]->data);
        $this->assertSame((string) $colleague->id, $audits[2]->actor_id);
    }

    #[Test]
    public function a_target_for_an_unknown_kpi_is_404_and_a_bad_one_is_422(): void
    {
        Sanctum::actingAs($this->teamMember());

        $this->putJson(self::API.'kpis/revenue/target', ['target' => 10])->assertNotFound();
        $this->putJson(self::API.'kpis/saccos_live/target', ['target' => 'lots'])->assertUnprocessable();
        $this->putJson(self::API.'kpis/saccos_live/target', ['target' => -1])->assertUnprocessable();
        $this->putJson(self::API.'kpis/saccos_live/target', [])->assertUnprocessable();
        $this->putJson(self::API.'kpis/saccos_live/target', ['target' => 5, 'period' => 'day'])->assertUnprocessable();
        $this->putJson(self::API.'kpis/tills_registered/target', ['target' => 101])->assertUnprocessable();
        $this->putJson(self::API.'kpis/tills_registered/target', ['target' => 95])->assertOk();

        $this->assertSame(['tills_registered'], TeamKpiTarget::query()->pluck('key')->all());
    }

    #[Test]
    public function figures_are_cached_briefly_but_a_new_target_shows_at_once(): void
    {
        Sanctum::actingAs($this->teamMember());
        $sacco = $this->makeSacco();
        $this->day($this->bus($sacco), '2026-11-24', 100, 1);

        $this->getJson(self::API.'kpis')->assertOk()->assertJsonPath('kpis.2.value', 100);

        $this->day($this->bus($sacco), '2026-11-25', 400, 2);
        $this->putJson(self::API.'kpis/collected/target', ['target' => 1000])->assertOk();

        $cached = $this->getJson(self::API.'kpis')->assertOk();
        $cached->assertJsonPath('kpis.2.value', 100);
        $cached->assertJsonPath('kpis.2.target', 1000);

        Carbon::setTestNow(now()->addSeconds(KpiCalculator::CACHE_SECONDS + 1));
        $this->getJson(self::API.'kpis')->assertOk()->assertJsonPath('kpis.2.value', 500);
    }
}
