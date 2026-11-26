<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use App\Enums\SaccoClaimStatus;
use App\Enums\UserType;
use App\Jobs\RegisterVehicleTill;
use App\Models\MpesaPaymentSetting;
use App\Models\PlatformNotification;
use App\Models\Sacco;
use App\Models\Team\TeamCard;
use App\Models\Team\TeamCardComment;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Mpesa\TillRegistrar;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Sacco\SaccoDirectory;
use App\Services\Team\AutoCards\AutoCards;
use App\Services\Team\AutoCards\TillRegistrationCards;
use App\Services\Team\TeamBoard;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The ops board's automatic cards: the platform opening a card when a till
 * fails, a head office holds buses back, a SACCO waits for review or a fraud
 * flag goes up -- and closing it when the problem goes away. Most tests drive
 * the real trigger (the registrar, the directory, the notifier) and read the
 * board's table; the API throttle is never in play.
 */
final class AutoCardsTest extends QueueTestCase
{
    private function registrar(): TillRegistrar
    {
        return app(TillRegistrar::class);
    }

    /** @return Collection<int, TeamCard> */
    private function cards(string $source, ?string $linkType = null, ?int $linkId = null)
    {
        return TeamCard::query()->where('source', $source)
            ->when($linkType !== null, fn ($q) => $q->where('link_type', $linkType)->where('link_id', $linkId))
            ->orderBy('id')->get();
    }

    private function openCard(string $source, string $linkType, int $linkId): ?TeamCard
    {
        return TeamCard::query()->where('source', $source)->where('link_type', $linkType)->where('link_id', $linkId)
            ->where('status', '!=', 'done')->first();
    }

    /** @return list<string> the card's comments, oldest first */
    private function comments(TeamCard $card): array
    {
        return TeamCardComment::query()->where('card_id', $card->id)->orderBy('id')->pluck('body')->all();
    }

    private function bus(?Sacco $sacco = null, string $till = '1885147'): Vehicle
    {
        $sacco ??= $this->makeSacco();
        $owner = $this->makeUser([], $sacco);
        $vehicle = $this->makeVehicle($sacco, $owner, $this->makeSeat());
        // Written past the model (as the bus importer would): no till
        // registration starts, the test decides the status.
        Vehicle::withoutGlobalScopes()->whereKey($vehicle->id)->update(['till_number' => $till, 'merchant_short_code' => $till]);

        return Vehicle::withoutGlobalScopes()->findOrFail($vehicle->id);
    }

    private function headOffice(Sacco $sacco, ?string $key = null, bool $live = true, string $code = '7071220'): MpesaPaymentSetting
    {
        return MpesaPaymentSetting::create([
            'sacco_id' => $sacco->id, 'name' => 'Head office '.$code,
            'consumer_key' => $key ?? '', 'consumer_secret' => $key === null ? '' : 'cs-'.$key,
            'business_short_code' => $code, 'pass_key' => 'pk', 'payment_mode' => 'CustomerBuyGoodsOnline',
            'is_live' => $live, 'status' => true,
        ]);
    }

    private function sweep(): void
    {
        $this->artisan('team:sync-auto-cards')->assertSuccessful();
    }

    private function flag(string $event, array $subject, array $data = [], string $severity = 'high', ?string $dedupeKey = null): PlatformNotification
    {
        return app(PlatformNotifier::class)->dispatch(new PlatformEvent(
            event: $event,
            severity: $severity,
            class: 'alert',
            title: 'Flag '.$event,
            summary: 'Something about '.$event,
            brand: 'testing',
            subject: $subject,
            data: $data,
            dedupeKey: $dedupeKey,
            windowMinutes: $dedupeKey === null ? 0 : 60,
        ));
    }

    private function superAdmin(): User
    {
        $user = $this->makeUser();
        $user->forceFill(['type' => UserType::Superadmin->value])->save();
        Permission::findOrCreate('View Platform Notifications', 'web');
        $user->givePermissionTo('View Platform Notifications');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    // ------------------------------------------------------------- till_registration

    #[Test]
    public function a_till_safaricom_refuses_opens_one_card_on_the_bus(): void
    {
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            '*/mpesa/c2b/v2/registerurl' => Http::response(['requestId' => 'x', 'errorCode' => '400.003.02', 'errorMessage' => 'Invalid ShortCode'], 400),
        ]);
        $world = $this->makeWorld();
        $this->headOffice($world['sacco'], 'ck');
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368', 'till_number' => '1885147'])->save();
        $this->assertNull($this->openCard('till_registration', 'vehicle', $world['vehicle']->id), 'pending is not a failure');

        // The real path: the queued job asks Safaricom and is refused.
        (new RegisterVehicleTill((int) $world['vehicle']->id))->handle($this->registrar());

        $this->assertSame(TillRegistrar::REFUSED, $world['vehicle']->fresh()->till_registration_status);
        $card = $this->openCard('till_registration', 'vehicle', $world['vehicle']->id);
        $this->assertNotNull($card);
        $this->assertSame('todo', $card->status);
        $this->assertNull($card->assignee_id);
        $this->assertNull($card->created_by);
        $this->assertSame('high', $card->priority);
        $this->assertSame($world['vehicle']->plate.' · till 1885147 · Safaricom refused it', $card->title);
        $this->assertStringContainsString('Safaricom: Invalid ShortCode', (string) $card->description);
        $this->assertStringContainsString($world['sacco']->name, (string) $card->description);
    }

    #[Test]
    public function the_same_failure_twice_is_one_card_and_the_card_tells_the_story(): void
    {
        $bus = $this->bus();

        $this->registrar()->note($bus, TillRegistrar::REFUSED);
        $this->registrar()->note($bus, TillRegistrar::PENDING);   // someone pressed Register till
        $this->registrar()->note($bus, TillRegistrar::GAVE_UP);   // ...and Safaricom never answered
        $this->registrar()->note($bus, TillRegistrar::GAVE_UP);   // no change: no event at all

        $cards = $this->cards('till_registration');
        $this->assertCount(1, $cards);
        $this->assertSame('todo', $cards[0]->status);
        $this->assertSame(['Registration ran again: Safaricom unreachable, gave up. '.TillRegistrar::MESSAGES[TillRegistrar::GAVE_UP]], $this->comments($cards[0]));
    }

    #[Test]
    public function statuses_that_are_not_a_bus_failure_raise_no_till_card(): void
    {
        foreach ([TillRegistrar::PENDING, TillRegistrar::UNREACHABLE, TillRegistrar::REGISTERED, TillRegistrar::BANK_ISSUED,
            TillRegistrar::NOT_OURS, TillRegistrar::NO_SHORT_CODE, TillRegistrar::NO_KEYS, TillRegistrar::NOT_LIVE] as $status) {
            $this->registrar()->note($this->bus(), $status);
        }

        $this->assertCount(0, $this->cards('till_registration'));
    }

    #[Test]
    public function registering_closes_the_card_with_a_system_comment_and_a_new_failure_opens_a_new_card(): void
    {
        $bus = $this->bus();
        $this->registrar()->note($bus, TillRegistrar::NEEDS_HEAD_OFFICE);
        $first = $this->openCard('till_registration', 'vehicle', $bus->id);
        $this->assertSame('normal', $first->priority);
        app(TeamBoard::class)->move($first, 'doing', null);

        $this->registrar()->note($bus, TillRegistrar::PENDING);
        $this->assertSame('doing', $first->fresh()->status, 'in flight: left as it is');

        $this->registrar()->note($bus, TillRegistrar::REGISTERED);
        $first->refresh();
        $this->assertSame('done', $first->status);
        $this->assertNotNull($first->done_at);
        $this->assertSame(['Closed itself: the till is registered with Safaricom now.'], $this->comments($first));

        // The problem comes back: a fresh card, the old one stays done.
        $this->registrar()->note($bus, TillRegistrar::REFUSED);
        $cards = $this->cards('till_registration', 'vehicle', $bus->id);
        $this->assertCount(2, $cards);
        $this->assertSame('done', $cards[0]->status);
        $this->assertSame('todo', $cards[1]->status);
    }

    #[Test]
    public function a_parked_card_is_left_in_waiting_and_told_once(): void
    {
        $bus = $this->bus();
        $this->registrar()->note($bus, TillRegistrar::BAD_KEYS);
        $card = $this->openCard('till_registration', 'vehicle', $bus->id);
        app(TeamBoard::class)->move($card, 'waiting', null);

        $this->registrar()->note($bus, TillRegistrar::REGISTERED);
        $this->sweep();
        $this->sweep();

        $card->refresh();
        $this->assertSame('waiting', $card->status);
        $this->assertSame(['Resolved on the platform: the till is registered with Safaricom now. Left in Waiting because someone parked it there.'], $this->comments($card));
    }

    #[Test]
    public function a_bank_issued_till_or_a_removed_till_closes_the_card_saying_why(): void
    {
        $bus = $this->bus();
        $this->registrar()->note($bus, TillRegistrar::REFUSED);
        // TillRequestsController::apply: a normal save, not the registrar.
        $bus->fresh()->forceFill(['till_registration_status' => TillRegistrar::BANK_ISSUED])->save();
        $card = $this->cards('till_registration', 'vehicle', $bus->id)->first();
        $this->assertSame('done', $card->status);
        $this->assertStringContainsString('no longer ours to register', $this->comments($card)[0]);

        $other = $this->bus();
        $this->registrar()->note($other, TillRegistrar::ALREADY_REGISTERED);
        $this->registrar()->note($other, TillRegistrar::NO_SHORT_CODE);
        $this->assertStringContainsString('no longer has a till', $this->comments($this->cards('till_registration', 'vehicle', $other->id)->first())[0]);

        $gone = $this->bus();
        $this->registrar()->note($gone, TillRegistrar::CONFIRM_HEAD_OFFICE);
        Vehicle::withoutGlobalScopes()->findOrFail($gone->id)->delete();
        $this->assertSame(['Closed itself: the bus was deleted.'], $this->comments($this->cards('till_registration', 'vehicle', $gone->id)->first()));
    }

    // ------------------------------------------------------------------ head_office

    #[Test]
    public function buses_waiting_on_a_keyless_head_office_open_one_card_for_the_sacco_and_saving_keys_closes_it(): void
    {
        $sacco = $this->makeSacco();
        $office = $this->headOffice($sacco, key: null, code: '5495094');
        $buses = [$this->bus($sacco), $this->bus($sacco)];
        // One transaction (a bulk Register tills): the SACCO is looked at once, after the commit.
        DB::transaction(function () use ($buses, $office): void {
            foreach ($buses as $bus) {
                Vehicle::withoutGlobalScopes()->whereKey($bus->id)->update(['mpesa_payment_setting_id' => $office->id]);
                $this->registrar()->note($bus->fresh(), TillRegistrar::NO_KEYS);
            }
        });

        $cards = $this->cards('head_office', 'sacco', $sacco->id);
        $this->assertCount(1, $cards);
        $this->assertSame($sacco->name.' · 2 buses waiting on head office 5495094 · no API keys', $cards[0]->title);
        $this->assertSame('high', $cards[0]->priority);
        $this->assertCount(0, $this->cards('till_registration'), 'one card for the head office, not one per bus');

        $office->forceFill(['consumer_key' => 'ck', 'consumer_secret' => 'cs'])->save();

        $card = $cards[0]->fresh();
        $this->assertSame('done', $card->status);
        $this->assertStringContainsString('Press Register tills on it to register the 2 buses', $this->comments($card)[0]);

        // The sweep agrees: the head office is ready, nothing reopens.
        $this->sweep();
        $this->assertCount(1, $this->cards('head_office'));
    }

    #[Test]
    public function a_head_office_on_the_test_system_holds_its_buses_until_it_goes_live(): void
    {
        $sacco = $this->makeSacco();
        $office = $this->headOffice($sacco, key: 'ck', live: false);
        $bus = $this->bus($sacco);
        Vehicle::withoutGlobalScopes()->whereKey($bus->id)->update(['mpesa_payment_setting_id' => $office->id]);
        $this->registrar()->note($bus->fresh(), TillRegistrar::NOT_LIVE);

        $card = $this->openCard('head_office', 'sacco', $sacco->id);
        $this->assertStringEndsWith('· on the Safaricom test system', $card->title);
        $this->assertStringContainsString('1 bus waiting', $card->title);

        $office->forceFill(['is_live' => true])->save();
        $this->assertSame('done', $card->fresh()->status);
    }

    #[Test]
    public function the_head_office_card_closes_when_no_bus_waits_any_more(): void
    {
        $sacco = $this->makeSacco();
        $this->headOffice($sacco, key: null);   // the SACCO's only live head office
        $bus = $this->bus($sacco);
        $this->registrar()->note($bus, TillRegistrar::NO_KEYS);
        $this->assertNotNull($this->openCard('head_office', 'sacco', $sacco->id), 'an unlinked bus falls back to the only live head office');

        $this->registrar()->note($bus->fresh(), TillRegistrar::NOT_OURS);

        $this->assertSame(['Closed itself: no bus waits on a head office without keys any more.'], $this->comments($this->cards('head_office')->first()));
    }

    // -------------------------------------------------------------- directory_claim

    #[Test]
    public function a_sacco_a_driver_typed_waits_for_review_on_the_board_until_approved(): void
    {
        $sacco = app(SaccoDirectory::class)->resolveOrSubmit('Kangema Shuttle Express');
        $again = app(SaccoDirectory::class)->resolveOrSubmit('Kangema Shuttle Express');
        $this->assertSame($sacco->id, $again->id);

        $cards = $this->cards('directory_claim');
        $this->assertCount(1, $cards);
        $this->assertSame('Kangema Shuttle Express · new SACCO to review', $cards[0]->title);
        $this->assertSame('sacco', $cards[0]->link_type);
        $this->assertStringContainsString('by a driver during onboarding', (string) $cards[0]->description);

        // An imported register entry is not a claim.
        Sacco::create(['name' => 'Imported From Sasra', 'status' => 1, 'brand' => 'testing', 'source' => 'sasra', 'claim_status' => SaccoClaimStatus::Directory]);
        $this->assertCount(1, $this->cards('directory_claim'));

        Sanctum::actingAs($this->superAdmin());
        $this->postJson('/api/v1/super/directory/'.$sacco->id.'/approve')->assertOk();

        $this->assertSame('done', $cards[0]->fresh()->status);
        $this->assertSame(['Closed itself: approved -- the SACCO is claimed now.'], $this->comments($cards[0]));
    }

    #[Test]
    public function rejecting_merging_or_deleting_a_waiting_sacco_closes_its_card(): void
    {
        $directory = app(SaccoDirectory::class);
        $rejected = $directory->resolveOrSubmit('Rejected Movers');
        $merged = $directory->resolveOrSubmit('Merged Movers');
        $deleted = $directory->resolveOrSubmit('Deleted Movers');
        $this->assertCount(3, $this->cards('directory_claim'));

        Sanctum::actingAs($this->superAdmin());
        $this->postJson('/api/v1/super/directory/'.$rejected->id.'/reject', ['reason' => 'Not a SACCO'])->assertOk();
        $this->postJson('/api/v1/super/directory/'.$merged->id.'/merge', ['into_sacco_id' => $this->makeSacco()->id])->assertOk();
        Sacco::withoutGlobalScopes()->findOrFail($deleted->id)->delete();

        foreach ([$rejected, $merged] as $sacco) {
            $card = $this->cards('directory_claim', 'sacco', $sacco->id)->first();
            $this->assertSame('done', $card->status);
            $this->assertSame(['Closed itself: rejected or merged into another SACCO (the entry is deactivated).'], $this->comments($card));
        }
        $this->assertSame(['Closed itself: the SACCO was deleted.'], $this->comments($this->cards('directory_claim', 'sacco', $deleted->id)->first()));
    }

    // ------------------------------------------------------------------------ fraud

    #[Test]
    public function a_fraud_flag_opens_a_card_on_its_bus_and_repeats_and_other_flags_share_it(): void
    {
        $bus = $this->bus();

        $first = $this->flag('vehicle.till.duplicate', ['type' => 'vehicle', 'id' => (string) $bus->id], severity: 'critical');
        $card = $this->openCard('fraud', 'vehicle', $bus->id);
        $this->assertNotNull($card);
        $this->assertSame('urgent', $card->priority);
        $this->assertSame($bus->plate.' · Flag vehicle.till.duplicate', $card->title);

        // The same flag again only ticks its count: same card, nothing said.
        $burst = $this->flag('driver.login.failed_burst', ['type' => 'vehicle', 'id' => $bus->id], ['vehicleId' => $bus->id, 'plate' => 'X'], dedupeKey: 'driver.login.failed_burst:X');
        $this->flag('driver.login.failed_burst', ['type' => 'vehicle', 'id' => $bus->id], ['vehicleId' => $bus->id, 'plate' => 'X'], dedupeKey: 'driver.login.failed_burst:X');
        $this->assertSame(2, $burst->fresh()->count);

        $this->assertCount(1, $this->cards('fraud'));
        $this->assertSame(['Also flagged: Flag driver.login.failed_burst -- Something about driver.login.failed_burst'], $this->comments($card));

        // Resolving one of two flags leaves the card open; the last closes it.
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/super/notifications/'.$first->id.'/resolve')->assertOk();
        $this->assertSame('todo', $card->fresh()->status);

        $this->postJson('/api/v1/super/notifications/'.$burst->id.'/resolve')->assertOk();
        $this->assertSame('done', $card->fresh()->status);
        $this->assertStringContainsString('every fraud flag on it is resolved', last($this->comments($card)));

        // A new flag after that: a new card.
        $this->flag('vehicle.plate.cross_sacco', ['type' => 'vehicle', 'id' => $bus->id]);
        $this->assertCount(2, $this->cards('fraud', 'vehicle', $bus->id));
    }

    #[Test]
    public function a_flag_about_a_driver_or_a_sacco_links_to_what_its_data_names(): void
    {
        $other = $this->bus();
        $sacco = $this->makeSacco();
        $bus = $this->bus($sacco);

        // A driver hopping buses: the lowest-numbered bus it names, whatever
        // order the list came in.
        $this->flag('driver.reassigned.rapid', ['type' => 'driver', 'id' => 99], ['driverId' => 99, 'vehicleIds' => [$bus->id, $other->id], 'saccoIds' => [$sacco->id]]);
        $this->assertNotNull($this->openCard('fraud', 'vehicle', $other->id));

        // Points drained at a SACCO.
        $this->flag('loyalty.redemption.spike', ['type' => 'sacco', 'id' => (string) $sacco->id], ['saccoId' => $sacco->id]);
        $this->assertNotNull($this->openCard('fraud', 'sacco', $sacco->id));

        // A login that got in after a burst: the bus the burst was on.
        $this->flag('driver.login.failed_burst', ['type' => 'vehicle', 'id' => $bus->id, 'label' => 'KDA999X'], ['plate' => 'KDA999X', 'vehicleId' => $bus->id], 'critical', 'driver.login.failed_burst:KDA999X');
        $this->flag('driver.login.suspicious_success', ['type' => 'driver', 'id' => 5, 'label' => 'KDA999X'], ['plate' => 'KDA999X', 'driverId' => 5], 'critical');
        $card = $this->openCard('fraud', 'vehicle', $bus->id);
        $this->assertCount(1, $this->comments($card), 'the success joined the burst\'s card');

        // A SACCO admin being guessed at: their SACCO.
        $adminSacco = $this->makeSacco();
        $admin = $this->makeUser([], $adminSacco);
        $this->flag('access.login.failed_burst', ['type' => 'user', 'id' => (string) $admin->id], ['userId' => $admin->id]);
        $this->assertNotNull($this->openCard('fraud', 'sacco', $adminSacco->id));

        // Not fraud, or nothing to link: no card.
        $before = TeamCard::query()->count();
        $this->flag('payment.callback.refused', ['type' => 'callback_source', 'id' => 'safaricom'], ['ip' => '10.0.0.1']);
        $this->flag('sacco.dormant', ['type' => 'sacco', 'id' => $sacco->id]);
        $this->flag('onboarding.velocity.spike', [], ['actorRef' => '10.0.0.1', 'saccoIds' => []]);
        $this->assertSame($before, TeamCard::query()->count());
    }

    #[Test]
    public function a_backlog_of_old_unresolved_flags_never_stops_a_card_closing(): void
    {
        // Over a thousand open flags nobody resolved, all older than the
        // board looks (vehicle.till.duplicate never dedupes: one per edit).
        $now = now();
        foreach (array_chunk(range(1, 1100), 500) as $chunk) {
            DB::table('platform_notifications')->insert(array_map(fn (int $i) => [
                'event' => 'vehicle.till.duplicate', 'severity' => 'high', 'delivery_class' => 'alert', 'title' => 'Old', 'summary' => 'Old',
                'subject' => json_encode(['type' => 'vehicle', 'id' => (string) (900000 + $i)]), 'data' => json_encode(['vehicleIds' => [900000 + $i]]),
                'count' => 1, 'first_occurred_at' => $now->copy()->subDays(60), 'occurred_at' => $now->copy()->subDays(60),
                'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }

        $bus = $this->bus();
        $flag = $this->flag('vehicle.till.duplicate', ['type' => 'vehicle', 'id' => (string) $bus->id], ['vehicleIds' => [$bus->id]]);
        $card = $this->openCard('fraud', 'vehicle', $bus->id);
        $this->assertNotNull($card);

        Sanctum::actingAs($this->superAdmin());
        $this->postJson('/api/v1/super/notifications/'.$flag->id.'/resolve')->assertOk();

        $this->assertSame('done', $card->fresh()->status);
        $this->assertSame(['Closed itself: every fraud flag on it is resolved in the notification centre.'], $this->comments($card));

        // The sweep closes through the same backlog too.
        $other = $this->bus();
        $missed = PlatformNotification::withoutEvents(fn () => PlatformNotification::create([
            'event' => 'vehicle.plate.cross_sacco', 'severity' => 'high', 'delivery_class' => 'alert', 'title' => 'Plate twice',
            'summary' => 'Plate twice', 'subject' => ['type' => 'vehicle', 'id' => $other->id], 'data' => [], 'count' => 1,
            'first_occurred_at' => now(), 'occurred_at' => now(),
        ]));
        $this->sweep();
        $swept = $this->openCard('fraud', 'vehicle', $other->id);
        $this->assertNotNull($swept);
        PlatformNotification::query()->whereKey($missed->id)->update(['resolved_at' => now()]);
        $this->sweep();
        $this->assertSame('done', $swept->fresh()->status);
    }

    #[Test]
    public function a_repeat_that_reorders_its_data_keeps_one_card_where_it_was(): void
    {
        $low = $this->makeSacco();
        $high = $this->makeSacco();

        // One national ID on three accounts; the repeat lists the SACCOs in
        // another order (the observer's query has no ORDER BY).
        $this->flag('driver.id_number.duplicate', [], ['driverIds' => [1, 2], 'saccoIds' => [$high->id, $low->id]], dedupeKey: 'driver.id_number.duplicate:abc');
        $this->flag('driver.id_number.duplicate', [], ['driverIds' => [1, 2, 3], 'saccoIds' => [$low->id, $high->id]], dedupeKey: 'driver.id_number.duplicate:abc');
        $this->sweep();

        $cards = $this->cards('fraud');
        $this->assertCount(1, $cards, 'never closed on one SACCO and reopened on the other');
        $this->assertSame([(int) $low->id, 'todo'], [(int) $cards[0]->link_id, $cards[0]->status]);
        $this->assertSame([], $this->comments($cards[0]));
    }

    #[Test]
    public function a_card_stays_open_while_any_open_flag_names_its_sacco(): void
    {
        $other = $this->makeSacco();
        $sacco = $this->makeSacco();
        $spike = $this->flag('loyalty.redemption.spike', ['type' => 'sacco', 'id' => (string) $sacco->id], ['saccoId' => $sacco->id]);
        $card = $this->openCard('fraud', 'sacco', $sacco->id);

        // Links to the lower id, but names this SACCO too.
        $this->flag('driver.id_number.duplicate', [], ['saccoIds' => [$other->id, $sacco->id]]);
        $this->assertSame(['Also flagged: Flag driver.id_number.duplicate -- Something about driver.id_number.duplicate'], $this->comments($card), 'joined the open card it names');

        Sanctum::actingAs($this->superAdmin());
        $this->postJson('/api/v1/super/notifications/'.$spike->id.'/resolve')->assertOk();
        $this->sweep();

        $this->assertSame('todo', $card->fresh()->status, 'a flag naming this SACCO is still open');
        $this->assertCount(1, $this->cards('fraud'));
    }

    #[Test]
    public function a_merged_saccos_card_closes_saying_so_and_its_peoples_flags_move_with_them(): void
    {
        $from = $this->makeSacco();
        $into = $this->makeSacco();
        $admin = $this->makeUser([], $from);
        $this->flag('access.login.failed_burst', ['type' => 'user', 'id' => (string) $admin->id], ['userId' => $admin->id]);
        $card = $this->openCard('fraud', 'sacco', $from->id);
        $this->assertNotNull($card);

        Sanctum::actingAs($this->superAdmin());
        $this->postJson('/api/v1/super/directory/'.$from->id.'/merge', ['into_sacco_id' => $into->id])->assertOk();
        $this->sweep();

        $this->assertSame('done', $card->fresh()->status);
        $this->assertStringContainsString('the SACCO was deactivated (rejected, or merged into another)', last($this->comments($card)));
        $this->assertNotNull($this->openCard('fraud', 'sacco', $into->id));
    }

    #[Test]
    public function a_head_office_that_is_gone_keeps_the_card_open_saying_so(): void
    {
        $sacco = $this->makeSacco();
        $office = $this->headOffice($sacco, key: null, code: '5495094');
        $bus = $this->bus($sacco);
        Vehicle::withoutGlobalScopes()->whereKey($bus->id)->update(['mpesa_payment_setting_id' => $office->id]);
        $this->registrar()->note($bus->fresh(), TillRegistrar::NO_KEYS);
        $card = $this->openCard('head_office', 'sacco', $sacco->id);
        $this->assertNotNull($card);

        $office->delete();
        $this->sweep();

        $this->assertSame('todo', $card->fresh()->status, 'pressing Register tills now would only say needs_head_office');
        $this->assertSame([], $this->comments($card));

        // Two live head offices and no link: still nothing to register under.
        $two = $this->makeSacco();
        $this->headOffice($two, key: 'a', code: '111');
        $this->headOffice($two, key: 'b', code: '222');
        $this->registrar()->note($this->bus($two), TillRegistrar::NO_KEYS);
        $this->sweep();
        $this->assertStringEndsWith('· several live head offices, none chosen for them', (string) $this->openCard('head_office', 'sacco', $two->id)?->title);
    }

    #[Test]
    public function a_problem_that_starts_again_after_a_person_closed_its_card_is_raised_even_if_its_event_was_lost(): void
    {
        $bus = $this->bus();
        $this->registrar()->note($bus, TillRegistrar::REFUSED);
        $card = $this->openCard('till_registration', 'vehicle', $bus->id);
        // The usual end of a parked card: the platform only comments on a
        // Waiting card, a person drags it to Done.
        app(TeamBoard::class)->move($card, 'waiting', null);
        $this->registrar()->note($bus->fresh(), TillRegistrar::REGISTERED);
        app(TeamBoard::class)->move($card->fresh(), 'done', null);

        // Weeks later it fails again, and the card work for it is lost.
        $this->travel(3)->weeks();
        Exceptions::fake();
        $this->app->instance(TillRegistrationCards::class, new class(app(TeamBoard::class)) extends TillRegistrationCards
        {
            public function sync(int $vehicleId, bool $event = true): void
            {
                throw new RuntimeException('lost');
            }
        });
        $this->registrar()->note($bus->fresh(), TillRegistrar::REFUSED);
        $this->app->forgetInstance(TillRegistrationCards::class);
        $this->assertNull($this->openCard('till_registration', 'vehicle', $bus->id));

        $this->sweep();
        $this->assertNotNull($this->openCard('till_registration', 'vehicle', $bus->id), 'a new occurrence, not the one the person closed');

        // A fraud flag first raised after a person's Done is new too; one
        // that was already there when they closed it is not.
        $flagged = $this->bus();
        $old = $this->flag('vehicle.plate.cross_sacco', ['type' => 'vehicle', 'id' => $flagged->id]);
        app(TeamBoard::class)->move($this->openCard('fraud', 'vehicle', $flagged->id), 'done', null);
        $this->sweep();
        $this->assertNull($this->openCard('fraud', 'vehicle', $flagged->id), 'the same flag: the person\'s call');

        $this->travel(1)->hours();
        PlatformNotification::withoutEvents(fn () => PlatformNotification::create([
            'event' => 'vehicle.till.duplicate', 'severity' => 'high', 'delivery_class' => 'alert', 'title' => 'Till twice',
            'summary' => 'Till twice', 'subject' => ['type' => 'vehicle', 'id' => (string) $flagged->id], 'data' => [], 'count' => 1,
            'first_occurred_at' => now(), 'occurred_at' => now(),
        ]));
        $this->sweep();
        $this->assertNotNull($this->openCard('fraud', 'vehicle', $flagged->id));
        $this->assertNull($old->fresh()->resolved_at);
    }

    #[Test]
    public function the_platform_closing_a_card_never_locks_the_done_column(): void
    {
        $board = app(TeamBoard::class);
        foreach (range(1, 3) as $i) {
            $board->create(['title' => 'Old '.$i, 'status' => 'done'], null);
        }
        $bottom = (float) TeamCard::query()->where('status', 'done')->max('position');
        $bus = $this->bus();
        $this->registrar()->note($bus, TillRegistrar::REFUSED);

        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            if (str_contains(strtolower($query->sql), 'for update') && str_contains($query->sql, '"status"')) {
                $locks[] = $query->sql;
            }
        });
        $this->registrar()->note($bus->fresh(), TillRegistrar::REGISTERED);

        $card = $this->cards('till_registration', 'vehicle', $bus->id)->first();
        $this->assertSame('done', $card->status);
        $this->assertSame($bottom + 1024.0, (float) $card->position, 'still the bottom of Done');
        $this->assertSame([], $locks);
    }

    #[Test]
    public function the_platforms_card_work_is_audited_as_the_platform_not_whoever_is_signed_in(): void
    {
        $admin = $this->makeUser([], $this->makeSacco());
        $this->actingAs($admin);

        $bus = $this->bus();
        $this->registrar()->note($bus, TillRegistrar::REFUSED);
        $this->registrar()->note($bus->fresh(), TillRegistrar::PENDING);
        $this->registrar()->note($bus->fresh(), TillRegistrar::REFUSED);   // an "again" comment
        $this->registrar()->note($bus->fresh(), TillRegistrar::REGISTERED);

        $rows = DB::table('audit_logs')->where('action', 'like', 'team.card.%')->get(['action', 'actor_type', 'actor_id', 'actor_label']);
        $this->assertEqualsCanonicalizing(
            ['team.card.created', 'team.card.commented', 'team.card.moved', 'team.card.commented'],
            $rows->pluck('action')->all(),
        );
        foreach ($rows as $row) {
            $this->assertSame(['system', null, 'automatic cards'], [$row->actor_type, $row->actor_id, $row->actor_label], $row->action);
        }

        // A person's own board work is still theirs.
        $card = $this->cards('till_registration')->first();
        app(TeamBoard::class)->comment($card, $admin, 'Called the SACCO');
        $this->assertSame((string) $admin->id, DB::table('audit_logs')->where('action', 'team.card.commented')->orderByDesc('id')->value('actor_id'));
    }

    // ----------------------------------------------------------------- never in the way

    #[Test]
    public function a_failure_in_card_code_never_breaks_the_write_that_triggered_it(): void
    {
        Exceptions::fake();
        $this->app->instance(TillRegistrationCards::class, new class(app(TeamBoard::class)) extends TillRegistrationCards
        {
            public function sync(int $vehicleId, bool $event = true): void
            {
                throw new RuntimeException('board is down');
            }
        });

        $bus = $this->bus();
        $outcome = $this->registrar()->register($bus);  // no head office: settled without Safaricom

        $this->assertSame(TillRegistrar::NEEDS_HEAD_OFFICE, $outcome['status']);
        $this->assertSame(TillRegistrar::NEEDS_HEAD_OFFICE, $bus->fresh()->till_registration_status);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'board is down');
        $this->assertCount(0, $this->cards('till_registration'));
    }

    #[Test]
    public function a_failed_query_in_card_code_rolls_back_only_the_card_work(): void
    {
        Exceptions::fake();
        // The board's table gone mid-flight: every card query fails.
        DB::statement('ALTER TABLE team_cards RENAME TO team_cards_away');

        $sacco = app(SaccoDirectory::class)->resolveOrSubmit('Survives Without A Board');
        $flag = $this->flag('vehicle.till.duplicate', ['type' => 'vehicle', 'id' => '1']);

        DB::statement('ALTER TABLE team_cards_away RENAME TO team_cards');

        // The writes stand, and the connection is still usable.
        $this->assertTrue(Sacco::withoutGlobalScopes()->whereKey($sacco->id)->exists());
        $this->assertTrue(PlatformNotification::query()->whereKey($flag->id)->exists());
        $this->assertSame(0, TeamCard::query()->count());
        Exceptions::assertReported(fn (QueryException $e) => str_contains($e->getMessage(), 'team_cards'));
    }

    #[Test]
    public function card_work_waits_for_the_commit_and_a_rolled_back_write_raises_nothing(): void
    {
        $bus = $this->bus();

        DB::transaction(function () use ($bus): void {
            $this->registrar()->note($bus, TillRegistrar::REFUSED);
            $this->assertCount(0, $this->cards('till_registration'), 'nothing before the commit');
        });
        $this->assertCount(1, $this->cards('till_registration'));

        $other = $this->bus();
        try {
            DB::transaction(function () use ($other): void {
                $this->registrar()->note($other, TillRegistrar::REFUSED);
                throw new RuntimeException('the caller failed');
            });
        } catch (RuntimeException) {
        }
        app(AutoCards::class)->flush();

        $this->assertCount(0, $this->cards('till_registration', 'vehicle', $other->id));
        $this->assertNull($other->fresh()->till_registration_status);
    }

    // ------------------------------------------------------------------ reconciliation

    #[Test]
    public function the_hourly_sweep_opens_what_events_missed_and_closes_what_resolved(): void
    {
        // State written with no events at all: a hand fix, a deploy mid-request.
        $failing = $this->bus();
        $inFlight = $this->bus();
        $sacco = $this->makeSacco();
        $office = $this->headOffice($sacco, key: null);
        $waiting = $this->bus($sacco);
        Vehicle::withoutGlobalScopes()->whereKey($failing->id)->update(['till_registration_status' => TillRegistrar::REFUSED, 'till_registration_note' => 'Safaricom: Invalid']);
        Vehicle::withoutGlobalScopes()->whereKey($inFlight->id)->update(['till_registration_status' => TillRegistrar::PENDING]);
        Vehicle::withoutGlobalScopes()->whereKey($waiting->id)->update(['till_registration_status' => TillRegistrar::NO_KEYS, 'mpesa_payment_setting_id' => $office->id]);
        $pending = Sacco::withoutEvents(fn () => Sacco::create(['name' => 'Swept Movers', 'status' => 1, 'brand' => 'testing', 'claim_status' => SaccoClaimStatus::PendingReview]));
        $flagged = $this->bus();
        $flag = PlatformNotification::withoutEvents(fn () => PlatformNotification::create([
            'event' => 'vehicle.plate.cross_sacco', 'severity' => 'high', 'delivery_class' => 'alert', 'title' => 'Plate twice',
            'summary' => 'Plate twice', 'subject' => ['type' => 'vehicle', 'id' => $flagged->id], 'data' => [], 'count' => 1,
            'first_occurred_at' => now(), 'occurred_at' => now(),
        ]));
        $stale = PlatformNotification::withoutEvents(fn () => PlatformNotification::create([
            'event' => 'vehicle.plate.cross_sacco', 'severity' => 'high', 'delivery_class' => 'alert', 'title' => 'Old',
            'summary' => 'Old', 'subject' => ['type' => 'vehicle', 'id' => $this->bus()->id], 'data' => [], 'count' => 1,
            'first_occurred_at' => now()->subDays(40), 'occurred_at' => now()->subDays(40),
        ]));
        $this->assertSame(0, TeamCard::query()->count());

        $this->sweep();

        $this->assertNotNull($this->openCard('till_registration', 'vehicle', $failing->id));
        $this->assertNull($this->openCard('till_registration', 'vehicle', $inFlight->id));
        $this->assertNotNull($this->openCard('head_office', 'sacco', $sacco->id));
        $this->assertNotNull($this->openCard('directory_claim', 'sacco', $pending->id));
        $this->assertNotNull($this->openCard('fraud', 'vehicle', $flagged->id));
        $this->assertSame(4, TeamCard::query()->count(), 'a months-old flag is history, not board work');

        // Idempotent: a second run changes nothing.
        $this->sweep();
        $this->assertSame(4, TeamCard::query()->count());
        $this->assertSame(0, TeamCardComment::query()->count());

        // Everything resolved behind the events' back.
        Vehicle::withoutGlobalScopes()->whereKey($failing->id)->update(['till_registration_status' => TillRegistrar::REGISTERED]);
        DB::table('mpesa_payment_settings')->where('id', $office->id)->update(['consumer_key' => 'ck', 'consumer_secret' => 'cs']);
        DB::table('saccos')->where('id', $pending->id)->update(['claim_status' => SaccoClaimStatus::Claimed->value]);
        PlatformNotification::query()->whereKey($flag->id)->update(['resolved_at' => now()]);

        $this->sweep();

        $this->assertSame(0, TeamCard::query()->where('status', '!=', 'done')->count());
        $this->assertSame(4, TeamCardComment::query()->where('body', 'like', 'Closed itself:%')->count());
        $this->assertNull($stale->fresh()->resolved_at);

        $this->sweep();
        $this->assertSame(4, TeamCardComment::query()->count());
    }

    #[Test]
    public function the_sweep_never_reopens_a_card_a_person_closed(): void
    {
        $bus = $this->bus();
        $this->registrar()->note($bus, TillRegistrar::ALREADY_REGISTERED);
        $card = $this->openCard('till_registration', 'vehicle', $bus->id);

        // "We have told the SACCO; nothing more for us to do."
        app(TeamBoard::class)->move($card, 'done', null);
        $this->sweep();
        $this->assertCount(1, $this->cards('till_registration'));

        // A real new event still opens a fresh card.
        $this->registrar()->note($bus, TillRegistrar::PENDING);
        $this->registrar()->note($bus, TillRegistrar::REFUSED);
        $this->assertCount(2, $this->cards('till_registration'));
    }

    #[Test]
    public function the_sweep_reopens_a_problem_that_came_back_after_the_platform_closed_it(): void
    {
        $bus = $this->bus();
        $this->registrar()->note($bus, TillRegistrar::REFUSED);
        $this->registrar()->note($bus, TillRegistrar::REGISTERED);
        $this->assertSame(0, TeamCard::query()->where('status', '!=', 'done')->count());

        // Back to refused with the event lost.
        Vehicle::withoutGlobalScopes()->whereKey($bus->id)->update(['till_registration_status' => TillRegistrar::REFUSED]);
        $this->sweep();

        $this->assertNotNull($this->openCard('till_registration', 'vehicle', $bus->id));
    }

    #[Test]
    public function the_sweep_is_scheduled_hourly(): void
    {
        $this->app->make(Kernel::class);
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'team:sync-auto-cards'));

        $this->assertNotEmpty($events);
        $this->assertSame('25 * * * *', $events->first()->expression);
    }
}
