<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Enums\UserType;
use App\Models\Sacco;
use App\Models\User;
use App\Models\VehicleUser;
use App\Services\Auth\LoginRefusals;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Why a crew sign-in failed is now written down -- for us, never the caller.
 *
 * 2026-11-22: 66 of 119 crew sign-ins failed, one phone 18 times in a row, and
 * nothing said whether the phone was unknown, the plate wrong, or the bus in
 * another SACCO. The answer to the caller is unchanged; the log is new.
 */
final class SignInRefusalsAreRecordedTest extends QueueTestCase
{
    private const LOGIN = '/api/v1/auth/mobile/login';

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $warnings = [];

    protected function setUp(): void
    {
        parent::setUp();
        Log::listen(function ($event) {
            if ($event->level === 'warning') {
                $this->warnings[] = [$event->message, $event->context];
            }
        });
    }

    private function crew(Sacco $sacco, string $phone, string $plate): User
    {
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicle->update(['plate' => $plate]);
        $driver = $this->makeUser([], $sacco);
        $driver->forceFill(['type' => UserType::Driver, 'phone' => $phone])->save();
        VehicleUser::create(['user_id' => $driver->id, 'vehicle_id' => $vehicle->id, 'sacco_id' => $sacco->id, 'status' => true, 'start_date' => now()]);

        return $driver->fresh();
    }

    /** @return list<array<string, mixed>> */
    private function refusals(): array
    {
        return array_values(array_map(fn ($w) => $w[1], array_filter($this->warnings, fn ($w) => $w[0] === 'sign-in refused')));
    }

    #[Test]
    public function each_kind_of_failure_is_named(): void
    {
        $sacco = $this->makeSacco();
        $driver = $this->crew($sacco, '254711000111', 'KDA001A');
        $elsewhere = $this->makeVehicle($this->makeSacco(), $this->makeUser(), $this->makeSeat());
        $elsewhere->update(['plate' => 'KDZ999Z']);

        $this->postJson(self::LOGIN, ['phone' => '0799000999', 'secret' => 'KDA001A'])->assertStatus(401);
        $this->postJson(self::LOGIN, ['phone' => '0711000111', 'secret' => 'KDB123B'])->assertStatus(401);
        $this->postJson(self::LOGIN, ['phone' => '0711000111', 'secret' => 'KDZ999Z'])->assertStatus(403);
        $this->postJson(self::LOGIN, ['phone' => '0711000111', 'secret' => 'not my password'])->assertStatus(401);

        $seen = array_map(fn ($c) => $c['reason'], $this->refusals());
        $this->assertSame([
            LoginRefusals::UNKNOWN_PHONE,
            LoginRefusals::UNKNOWN_PLATE,
            LoginRefusals::OTHER_SACCO,
            LoginRefusals::WRONG_PASSWORD,
        ], $seen);

        $other = $this->refusals()[2];
        $this->assertSame((int) $driver->id, $other['user_id']);
        $this->assertSame((int) $elsewhere->id, $other['vehicle_id']);
    }

    #[Test]
    public function nothing_typed_is_ever_written_down(): void
    {
        $sacco = $this->makeSacco();
        $this->crew($sacco, '254711000111', 'KDA001A');

        $this->postJson(self::LOGIN, ['phone' => '0711000111', 'secret' => 'KDB123B'])->assertStatus(401);
        $this->postJson(self::LOGIN, ['phone' => '0711000111', 'secret' => 'S3cret-Pass!'])->assertStatus(401);

        $logged = json_encode($this->refusals());
        foreach (['0711000111', '711000111', '254711000111', 'KDB123B', 'S3cret-Pass!'] as $typed) {
            $this->assertStringNotContainsString($typed, (string) $logged);
        }
        // But one person's attempts group together, whichever way they type the number.
        $this->assertSame(LoginRefusals::phoneHash('0711000111'), $this->refusals()[0]['phone_hash']);
        $this->assertSame(LoginRefusals::phoneHash('+254 711 000 111'), $this->refusals()[1]['phone_hash']);
    }

    #[Test]
    public function a_password_that_looks_like_a_plate_and_works_logs_nothing(): void
    {
        // Tried as a plate first (and refused), then as the password (and
        // accepted): a successful sign-in, so no refusal is written.
        $driver = $this->crew($this->makeSacco(), '254711000111', 'KDA001A');
        $driver->forceFill(['password' => bcrypt('KDB777B')])->save();

        $this->postJson(self::LOGIN, ['phone' => '0711000111', 'secret' => 'KDB777B'])->assertOk();

        $this->assertSame([], $this->refusals());
    }

    #[Test]
    public function when_plate_and_password_both_fail_the_plate_reason_is_written_once(): void
    {
        $this->crew($this->makeSacco(), '254711000111', 'KDA001A');

        $this->postJson(self::LOGIN, ['phone' => '0711000111', 'secret' => 'KDB777B'])->assertStatus(401);

        $this->assertSame([LoginRefusals::UNKNOWN_PLATE], array_map(fn ($c) => $c['reason'], $this->refusals()));
    }

    #[Test]
    public function an_inactive_account_is_named_as_such(): void
    {
        $user = $this->makeUser([], $this->makeSacco(), false);
        $user->forceFill(['phone' => '254722000222'])->save();

        $this->postJson(self::LOGIN, ['phone' => '0722000222', 'secret' => 'password'])->assertStatus(401);

        $this->assertSame([LoginRefusals::INACTIVE_ACCOUNT], array_map(fn ($c) => $c['reason'], $this->refusals()));
    }

    #[Test]
    public function the_caller_is_told_nothing_new(): void
    {
        $this->crew($this->makeSacco(), '254711000111', 'KDA001A');

        $this->postJson(self::LOGIN, ['phone' => '0711000111', 'secret' => 'KDB123B'])
            ->assertStatus(401)
            ->assertJsonMissingPath('reason');
    }
}
