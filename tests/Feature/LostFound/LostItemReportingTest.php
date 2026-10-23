<?php

declare(strict_types=1);

namespace Tests\Feature\LostFound;

use App\Enums\LostItemStatus;
use App\Models\FirebaseToken;
use App\Models\LostItemReport;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use App\Notifications\PlatformNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A passenger reports something left on a matatu and the crew on that bus
 * answers.
 *
 * The rule the whole feature turns on: the crew never learn who reported an
 * item, and the passenger never learns which crew member answered. Only the
 * report and the bus cross between them.
 */
final class LostItemReportingTest extends QueueTestCase
{
    /** @return array{world: array, vehicle: Vehicle, passenger: User, crew: User} */
    private function scene(): array
    {
        $world = $this->makeWorld();
        $vehicle = $world['vehicle'];

        $passenger = $this->makeUser([], null);
        $passenger->forceFill(['firstname' => 'Grace', 'lastname' => 'Wanjiru', 'phone' => '0712345678'])->save();

        $crew = $this->makeUser([], $world['sacco']);
        VehicleUser::create([
            'user_id' => $crew->id,
            'vehicle_id' => $vehicle->id,
            'sacco_id' => $world['sacco']->id,
            'start_date' => Carbon::now()->subHours(3),
            'status' => true,
        ]);

        return compact('world', 'vehicle', 'passenger', 'crew');
    }

    /** @return array<string, mixed> */
    private function payload(Vehicle $vehicle, array $over = []): array
    {
        return array_merge([
            'plate' => $vehicle->plate,
            'trip_date' => Carbon::today()->toDateString(),
            'category' => 'phone',
            'description' => 'Black Tecno Spark in a blue cover',
        ], $over);
    }

    // ------------------------------------------------------------- reporting

    #[Test]
    public function a_passenger_reports_an_item_and_the_crew_on_that_bus_are_told(): void
    {
        Notification::fake();
        $s = $this->scene();
        Sanctum::actingAs($s['passenger']);

        $response = $this->postJson('/api/v1/auth/lost-items', $this->payload($s['vehicle']))
            ->assertStatus(201);

        $response->assertJsonPath('report.status', 'open');
        $this->assertMatchesRegularExpression('/^lf_\d+$/', $response->json('report.id'));
        $this->assertMatchesRegularExpression('/^LF-\d+$/', $response->json('report.reference'));

        Notification::assertSentTo($s['crew'], PlatformNotification::class);
        // The passenger is not told about their own report.
        Notification::assertNotSentTo($s['passenger'], PlatformNotification::class);
    }

    #[Test]
    public function an_unknown_plate_is_refused_with_a_code_the_app_can_branch_on(): void
    {
        $s = $this->scene();
        Sanctum::actingAs($s['passenger']);

        $this->postJson('/api/v1/auth/lost-items', $this->payload($s['vehicle'], ['plate' => 'KDC 482X']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'unknown_plate');
    }

    #[Test]
    public function a_fourth_open_report_is_refused(): void
    {
        $s = $this->scene();
        Sanctum::actingAs($s['passenger']);

        for ($i = 0; $i < LostItemReport::MAX_OPEN; $i++) {
            $this->postJson('/api/v1/auth/lost-items', $this->payload($s['vehicle']))->assertStatus(201);
        }

        $this->postJson('/api/v1/auth/lost-items', $this->payload($s['vehicle']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'too_many_open');
    }

    #[Test]
    public function a_trip_date_outside_the_window_is_rejected(): void
    {
        $s = $this->scene();
        Sanctum::actingAs($s['passenger']);

        foreach ([Carbon::tomorrow(), Carbon::today()->subDays(20)] as $date) {
            $this->postJson('/api/v1/auth/lost-items', $this->payload($s['vehicle'], [
                'trip_date' => $date->toDateString(),
            ]))->assertStatus(400);
        }
    }

    #[Test]
    public function a_passenger_only_ever_sees_their_own_reports(): void
    {
        $s = $this->scene();
        $other = $this->makeUser([], null);

        Sanctum::actingAs($other);
        $this->postJson('/api/v1/auth/lost-items', $this->payload($s['vehicle']))->assertStatus(201);
        $theirs = LostItemReport::firstOrFail();

        Sanctum::actingAs($s['passenger']);
        $this->getJson('/api/v1/auth/lost-items')->assertOk()->assertJsonPath('total', 0);
        // Someone else's report is NOT FOUND, never forbidden, so an id cannot
        // be probed for existence.
        $this->getJson('/api/v1/auth/lost-items/'.$theirs->publicId())->assertStatus(404);
    }

    // --------------------------------------------------------------- privacy

    #[Test]
    public function the_crew_never_see_who_reported_the_item(): void
    {
        $s = $this->scene();
        Sanctum::actingAs($s['passenger']);
        $this->postJson('/api/v1/auth/lost-items', $this->payload($s['vehicle']))->assertStatus(201);

        Sanctum::actingAs($s['crew']);
        $body = $this->getJson('/api/v1/auth/crew/lost-items')->assertOk()->content();

        $this->assertStringNotContainsString('0712345678', $body, 'The crew must never receive the passenger phone.');
        $this->assertStringNotContainsString('Grace', $body, 'The crew must never receive the passenger name.');
        $this->assertStringNotContainsString('contact_phone', $body);
    }

    #[Test]
    public function the_crew_push_never_carries_the_passengers_details(): void
    {
        $s = $this->scene();
        FirebaseToken::create(['firebase_token' => 'tok-crew', 'user_id' => $s['crew']->id, 'locale' => 'en']);

        Notification::fake();
        Sanctum::actingAs($s['passenger']);
        $this->postJson('/api/v1/auth/lost-items', $this->payload($s['vehicle']))->assertStatus(201);

        Notification::assertSentTo($s['crew'], PlatformNotification::class, function (PlatformNotification $n): bool {
            $text = $n->title.' '.$n->message.' '.json_encode($n->params);

            return ! str_contains($text, '0712345678') && ! str_contains($text, 'Grace');
        });
    }

    // ------------------------------------------------------------ crew answer

    #[Test]
    public function the_crew_mark_it_found_and_the_passenger_is_told_where(): void
    {
        $s = $this->scene();
        Sanctum::actingAs($s['passenger']);
        $this->postJson('/api/v1/auth/lost-items', $this->payload($s['vehicle']))->assertStatus(201);
        $report = LostItemReport::firstOrFail();

        Notification::fake();
        Sanctum::actingAs($s['crew']);

        $this->postJson('/api/v1/auth/crew/lost-items/'.$report->publicId().'/found', [
            'where' => 'at_stage',
            'place_name' => 'Odeon stage',
            'note' => 'Left with the stage manager, Peter.',
        ])->assertOk()
            ->assertJsonPath('report.status', 'found')
            ->assertJsonPath('report.found.where', 'at_stage')
            ->assertJsonPath('report.found.place_name', 'Odeon stage');

        $fresh = $report->fresh();
        $this->assertNotNull($fresh->keep_until, 'A found item needs a collection deadline.');
        $this->assertSame(
            Carbon::today()->addDays(LostItemReport::KEEP_DAYS)->toDateString(),
            $fresh->keep_until->toDateString(),
        );

        Notification::assertSentTo($s['passenger'], PlatformNotification::class);
    }

    #[Test]
    public function at_stage_requires_naming_the_stage(): void
    {
        $s = $this->scene();
        Sanctum::actingAs($s['passenger']);
        $this->postJson('/api/v1/auth/lost-items', $this->payload($s['vehicle']))->assertStatus(201);
        $report = LostItemReport::firstOrFail();

        Sanctum::actingAs($s['crew']);
        $this->postJson('/api/v1/auth/crew/lost-items/'.$report->publicId().'/found', ['where' => 'at_stage'])
            ->assertStatus(400);
    }

    #[Test]
    public function a_report_can_only_be_answered_once(): void
    {
        $s = $this->scene();
        Sanctum::actingAs($s['passenger']);
        $this->postJson('/api/v1/auth/lost-items', $this->payload($s['vehicle']))->assertStatus(201);
        $report = LostItemReport::firstOrFail();

        Sanctum::actingAs($s['crew']);
        $this->postJson('/api/v1/auth/crew/lost-items/'.$report->publicId().'/not-found', ['note' => 'Checked everywhere.'])
            ->assertOk();

        $this->postJson('/api/v1/auth/crew/lost-items/'.$report->publicId().'/found', ['where' => 'with_crew'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'already_answered');
    }

    #[Test]
    public function crew_cannot_touch_a_report_for_another_bus(): void
    {
        $s = $this->scene();
        Sanctum::actingAs($s['passenger']);
        $this->postJson('/api/v1/auth/lost-items', $this->payload($s['vehicle']))->assertStatus(201);
        $report = LostItemReport::firstOrFail();

        // A crew member on a different bus entirely.
        $otherWorld = $this->makeWorld();
        $otherCrew = $this->makeUser([], $otherWorld['sacco']);
        VehicleUser::create([
            'user_id' => $otherCrew->id,
            'vehicle_id' => $otherWorld['vehicle']->id,
            'sacco_id' => $otherWorld['sacco']->id,
            'start_date' => Carbon::now()->subHour(),
            'status' => true,
        ]);

        Sanctum::actingAs($otherCrew);
        $this->getJson('/api/v1/auth/crew/lost-items')->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/api/v1/auth/crew/lost-items/'.$report->publicId().'/found', ['where' => 'with_crew'])
            ->assertStatus(404);
    }

    // --------------------------------------------------- passenger closing it

    #[Test]
    public function collected_only_works_once_the_crew_said_found(): void
    {
        $s = $this->scene();
        Sanctum::actingAs($s['passenger']);
        $this->postJson('/api/v1/auth/lost-items', $this->payload($s['vehicle']))->assertStatus(201);
        $report = LostItemReport::firstOrFail();

        $this->postJson('/api/v1/auth/lost-items/'.$report->publicId().'/collected')
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_collectable');

        $report->forceFill(['status' => LostItemStatus::Found->value, 'found_at' => Carbon::now()])->save();

        $this->postJson('/api/v1/auth/lost-items/'.$report->publicId().'/collected')
            ->assertOk()
            ->assertJsonPath('report.status', 'collected');
    }

    #[Test]
    public function the_timeline_records_every_step(): void
    {
        $s = $this->scene();
        Sanctum::actingAs($s['passenger']);
        $this->postJson('/api/v1/auth/lost-items', $this->payload($s['vehicle']))->assertStatus(201);
        $report = LostItemReport::firstOrFail();

        Sanctum::actingAs($s['crew']);
        $this->postJson('/api/v1/auth/crew/lost-items/'.$report->publicId().'/found', [
            'where' => 'with_crew', 'note' => 'Conductor has it.',
        ])->assertOk();

        Sanctum::actingAs($s['passenger']);
        $timeline = $this->getJson('/api/v1/auth/lost-items/'.$report->publicId())
            ->assertOk()->json('report.timeline');

        $this->assertSame(['open', 'found'], array_column($timeline, 'status'));
        $this->assertSame('Conductor has it.', $timeline[1]['note']);
    }

    // ----------------------------------------------------------------- photo

    #[Test]
    public function a_found_photo_is_served_only_through_a_signed_link(): void
    {
        Storage::fake('local');
        $s = $this->scene();
        Sanctum::actingAs($s['passenger']);
        $this->postJson('/api/v1/auth/lost-items', $this->payload($s['vehicle']))->assertStatus(201);
        $report = LostItemReport::firstOrFail();

        Sanctum::actingAs($s['crew']);
        $this->postJson('/api/v1/auth/crew/lost-items/'.$report->publicId().'/found', [
            'where' => 'with_crew',
            'photo' => \Illuminate\Http\UploadedFile::fake()->image('found.jpg'),
        ])->assertOk();

        Sanctum::actingAs($s['passenger']);
        $url = $this->getJson('/api/v1/auth/lost-items/'.$report->publicId())
            ->assertOk()->json('report.found.photo_url');

        $this->assertNotNull($url, 'A photo must produce a URL.');
        $this->assertStringContainsString('signature=', $url);

        // The same path without the signature is refused.
        $this->get(parse_url($url, PHP_URL_PATH))->assertStatus(403);
    }

    // --------------------------------------------------------------- expiry

    #[Test]
    public function unanswered_and_uncollected_reports_are_closed_by_the_nightly_sweep(): void
    {
        $s = $this->scene();
        Sanctum::actingAs($s['passenger']);
        $this->postJson('/api/v1/auth/lost-items', $this->payload($s['vehicle']))->assertStatus(201);

        $stale = LostItemReport::firstOrFail();
        $stale->forceFill(['created_at' => Carbon::now()->subDays(20)])->save();

        $uncollected = LostItemReport::create([
            'user_id' => $s['passenger']->id,
            'vehicle_id' => $s['vehicle']->id,
            'category' => 'bag',
            'description' => 'Brown leather bag',
            'trip_date' => Carbon::today()->subDays(5)->toDateString(),
            'status' => LostItemStatus::Found->value,
            'found_at' => Carbon::now()->subDays(16),
            'keep_until' => Carbon::today()->subDays(2)->toDateString(),
        ]);

        $this->artisan('lostitems:expire')->assertExitCode(0);

        $this->assertSame(LostItemStatus::Closed, $stale->fresh()->status);
        $this->assertSame(LostItemStatus::Closed, $uncollected->fresh()->status);
        $this->assertSame('No answer from the crew', $stale->fresh()->events()->latest('id')->first()->note);
        $this->assertSame('Handed to the SACCO office', $uncollected->fresh()->events()->latest('id')->first()->note);
    }
}
