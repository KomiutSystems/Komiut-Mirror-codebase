<?php

declare(strict_types=1);

namespace Tests\Feature\Passenger;

use App\Enums\PaymentMethod;
use App\Enums\UserType;
use App\Models\Booking;
use App\Models\FirebaseToken;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A passenger can delete their own account, and what stays behind carries
 * nothing that says who they were.
 */
final class AccountDeletionTest extends QueueTestCase
{
    private const URL = '/api/v1/auth/account';

    private function passenger(string $phone = '254712345678'): User
    {
        $u = $this->makeUser();
        $u->forceFill([
            'type' => UserType::Passenger, 'phone' => $phone, 'firstname' => 'Wanjiku', 'lastname' => 'Njeri',
            'id_number' => '12345678', 'dob' => '1990-01-01', 'provider' => 'google', 'provider_id' => 'g-123',
        ])->save();

        return $u->fresh();
    }

    #[Test]
    public function a_passenger_deletes_themselves_and_their_identity_goes_with_them(): void
    {
        $world = $this->makeWorld();
        $wanjiku = $this->passenger();
        FirebaseToken::create(['user_id' => $wanjiku->id, 'firebase_token' => 'fcm-abc', 'platform' => 'android', 'device_id' => 'd1']);
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Completed', 'Completed'), $world['owner']);
        $booking = $this->makeBooking($queue, $wanjiku, $world['from'], $world['to'], 'Wanjiku');
        $booking->update(['paid' => true, 'payment_method' => PaymentMethod::Mpesa]);
        $wanjiku->createToken('app'); // a live session on another device

        Sanctum::actingAs($wanjiku);
        $this->deleteJson(self::URL, ['phone' => '0712345678'])
            ->assertOk()->assertJsonPath('deleted', true);

        $row = User::withoutGlobalScopes()->find($wanjiku->id);
        $this->assertSame('Deleted', $row->firstname);
        $this->assertSame('deleted'.$wanjiku->id, $row->phone);
        $this->assertNull($row->id_number);
        $this->assertNull($row->provider_id);
        $this->assertFalse((bool) $row->status);

        // Every way back in is gone.
        $this->assertSame(0, $wanjiku->tokens()->count());
        $this->assertSame(0, FirebaseToken::where('user_id', $wanjiku->id)->count());

        // The SACCO's financial record stays, pointing at nobody.
        $this->assertTrue((bool) Booking::withoutGlobalScopes()->find($booking->id)->paid);
        $this->assertSame($wanjiku->id, (int) Booking::withoutGlobalScopes()->find($booking->id)->user_id);
    }

    #[Test]
    public function the_phone_must_be_retyped(): void
    {
        $wanjiku = $this->passenger();
        Sanctum::actingAs($wanjiku);

        $this->deleteJson(self::URL, ['phone' => '0700000000'])->assertStatus(422);
        $this->deleteJson(self::URL)->assertStatus(422);

        $this->assertSame('Wanjiku', User::withoutGlobalScopes()->find($wanjiku->id)->firstname, 'nothing happened');
    }

    #[Test]
    public function crew_and_office_accounts_are_the_saccos_to_remove(): void
    {
        $world = $this->makeWorld();
        $driver = $this->makeUser([], $world['sacco']);
        $driver->forceFill(['type' => UserType::Driver, 'phone' => '254700000001'])->save();
        Sanctum::actingAs($driver);

        $this->deleteJson(self::URL, ['phone' => '0700000001'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'This account is managed by your SACCO. Ask the SACCO office to remove it.');
    }

    #[Test]
    public function the_public_deletion_page_is_reachable_with_no_app_key_and_says_what_happens(): void
    {
        // A store reviewer opens this in a browser: no app key, any Host.
        $r = $this->get('/api/legal/account-deletion');

        $r->assertOk();
        $this->assertStringStartsWith('text/html', $r->headers->get('Content-Type'));
        $r->assertSee('Delete your Komiut account')
            ->assertSee('Settings')
            ->assertSee('What is deleted')
            ->assertSee('What is kept');
    }
}
