<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Auth\Roles;
use App\Enums\Financier;
use App\Jobs\RegisterVehicleTill;
use App\Models\Mpesa;
use App\Models\MpesaPaymentSetting;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Mpesa\TillRegistrar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * What the dashboard needs to show and act on a till's registration: the
 * status in the lists, one call to re-register a head office's stuck buses,
 * one store number per bus, and no bank fields for a bank.
 */
final class TillRegistrationVisibilityTest extends QueueTestCase
{
    private const STAFF = ['View Vehicles', 'Add Vehicles', 'Edit Vehicles', 'View Payment Settings', 'Edit Payment Settings'];

    private function connection(Sacco $sacco, string $headOffice, bool $keys = true): MpesaPaymentSetting
    {
        return MpesaPaymentSetting::create([
            'sacco_id' => $sacco->id, 'consumer_key' => $keys ? 'ck-'.$headOffice : '', 'consumer_secret' => $keys ? 'cs-'.$headOffice : '',
            'business_short_code' => $headOffice, 'pass_key' => 'pk', 'payment_mode' => 'CustomerBuyGoodsOnline',
            'is_live' => true, 'status' => true,
        ]);
    }

    private function bank(Financier $financier, array $permissions): User
    {
        $user = $this->makeUser($permissions, null);
        Role::findOrCreate(Roles::BANK_VIEWER, 'web');
        $user->assignRole(Roles::BANK_VIEWER);
        $user->forceFill(['financier' => $financier->value])->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    #[Test]
    public function the_lists_carry_the_registration_status_for_staff_and_not_for_a_bank(): void
    {
        $world = $this->makeWorld();
        $world['vehicle']->forceFill([
            'merchant_short_code' => '1417368', 'till_number' => '1885147', 'financier' => Financier::Ncba->value,
            'till_registration_status' => TillRegistrar::BAD_KEYS, 'till_registration_note' => TillRegistrar::MESSAGES[TillRegistrar::BAD_KEYS],
        ])->saveQuietly();

        Sanctum::actingAs($this->makeUser(self::STAFF, $world['sacco']));
        $vehicle = $this->getJson('/api/v1/auth/vehicles')->assertOk()->json('vehicles.0');
        $this->assertSame(TillRegistrar::BAD_KEYS, $vehicle['till_registration_status']);
        $this->assertSame(TillRegistrar::MESSAGES[TillRegistrar::BAD_KEYS], $vehicle['till_registration_note']);
        $this->assertArrayHasKey('till_registration_attempted_at', $vehicle);

        $tills = $this->getJson('/api/v1/auth/mpesa/tills')->assertOk();
        $row = $tills->json('tills.0');
        $this->assertSame(TillRegistrar::BAD_KEYS, $row['till_registration_status']);
        $this->assertSame(['id' => $world['sacco']->id, 'name' => $world['sacco']->name], $row['sacco']);
        $this->assertSame(Financier::Ncba->value, $row['financier']);
        $this->assertNotNull($tills->json('coverage'));

        // A bank -- even one holding the payment-settings permission -- sees
        // its buses and their SACCO, and no bank name, coverage or status.
        Sanctum::actingAs($this->bank(Financier::Ncba, ['View Vehicles', 'View Payment Settings']));
        $vehicle = $this->getJson('/api/v1/auth/vehicles')->assertOk()->json('vehicles.0');
        $this->assertArrayNotHasKey('till_registration_status', $vehicle);
        $this->assertArrayNotHasKey('till_registration_note', $vehicle);

        $tills = $this->getJson('/api/v1/auth/mpesa/tills')->assertOk();
        $tills->assertJsonMissingPath('coverage');
        $row = $tills->json('tills.0');
        $this->assertSame($world['sacco']->name, $row['sacco']['name']);
        foreach (['financier', 'coop_till', 'till_registration_status', 'till_registration_note'] as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }
    }

    #[Test]
    public function fixing_a_head_offices_keys_then_one_call_requeues_its_stuck_buses(): void
    {
        Http::fake();
        $world = $this->makeWorld();
        $office = $this->connection($world['sacco'], '5495094', keys: false);

        // Saved while the head office had no keys: stuck.
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368', 'till_number' => '1885147'])->save();
        $stuck = $world['vehicle']->fresh();
        $this->assertSame(TillRegistrar::NO_KEYS, $stuck->till_registration_status);

        $noStore = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $bankBus = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $bankBus->forceFill(['merchant_short_code' => '4560047', 'coop_till' => '01101233137021'])->saveQuietly();
        $done = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $done->forceFill(['merchant_short_code' => '1417369', 'till_registration_status' => TillRegistrar::REGISTERED])->saveQuietly();

        $office->forceFill(['consumer_key' => 'ck', 'consumer_secret' => 'cs'])->save();
        Bus::fake([RegisterVehicleTill::class]);

        Sanctum::actingAs($this->makeUser(['View Payment Settings'], $world['sacco']));
        $this->postJson("/api/v1/auth/mpesa/connections/{$office->id}/register-tills")->assertForbidden();

        Sanctum::actingAs($this->makeUser(self::STAFF, $world['sacco']));
        $response = $this->postJson("/api/v1/auth/mpesa/connections/{$office->id}/register-tills")->assertOk();

        $this->assertSame(1, $response->json('queued'));
        Bus::assertDispatched(RegisterVehicleTill::class, fn (RegisterVehicleTill $job) => $job->vehicleId === $stuck->id);
        Bus::assertNotDispatched(RegisterVehicleTill::class, fn (RegisterVehicleTill $job) => $job->vehicleId === $done->id);
        $this->assertSame(TillRegistrar::PENDING, $stuck->fresh()->till_registration_status);

        $skipped = collect($response->json('skipped'))->keyBy('vehicle_id');
        $this->assertSame(TillRegistrar::NO_SHORT_CODE, $skipped[$noStore->id]['reason']);
        $this->assertSame(TillRegistrar::NOT_OURS, $skipped[$bankBus->id]['reason']);
        $this->assertSame(['vehicle_id', 'plate', 'reason'], array_keys($skipped[$noStore->id]));
        $this->assertFalse($skipped->has($done->id), 'a registered bus is left alone');

        // Another SACCO's head office is not found.
        $other = $this->connection($this->makeSacco(), '3020809');
        $this->postJson("/api/v1/auth/mpesa/connections/{$other->id}/register-tills")->assertNotFound();
    }

    #[Test]
    public function a_store_number_already_on_another_bus_is_refused_and_nothing_changes(): void
    {
        Http::fake();
        $world = $this->makeWorld();
        $world['vehicle']->forceFill(['merchant_short_code' => '4321075', 'plate' => 'KDY 599G'])->saveQuietly();
        Sanctum::actingAs($this->makeUser(self::STAFF, $world['sacco']));

        $this->postJson('/api/v1/auth/vehicles/add', [
            'id' => 0, 'plate' => 'KDY 700A', 'seat' => $world['seat']->name, 'status' => 1, 'merchant_short_code' => 4321075,
        ])->assertStatus(422)->assertExactJson(['errors' => ['merchant_short_code' => ['Store number 4321075 is already on KDY 599G.']]]);
        $this->assertNull(Vehicle::withoutGlobalScopes()->where('plate', 'KDY 700A')->first());

        // Another SACCO's bus is not named.
        $foreign = $this->makeVehicle($this->makeSacco(), $world['owner'], $world['seat']);
        $foreign->forceFill(['merchant_short_code' => '4321099'])->saveQuietly();
        $this->postJson('/api/v1/auth/vehicles/add', [
            'id' => 0, 'plate' => 'KDY 701A', 'seat' => $world['seat']->name, 'status' => 1, 'merchant_short_code' => 4321099,
        ])->assertStatus(422)->assertJsonPath('errors.merchant_short_code.0', "Store number 4321099 is already on another SACCO's bus.");

        // NCBA's aggregator paybill is shared by design, and a bus keeps its own number on edit.
        $this->postJson('/api/v1/auth/vehicles/add', [
            'id' => 0, 'plate' => 'KDY 702A', 'seat' => $world['seat']->name, 'status' => 1, 'merchant_short_code' => 880100,
        ])->assertOk();
        $this->postJson('/api/v1/auth/vehicles/add', [
            'id' => 0, 'plate' => 'KDY 703A', 'seat' => $world['seat']->name, 'status' => 1, 'merchant_short_code' => 880100,
        ])->assertOk();
        $this->postJson('/api/v1/auth/vehicles/add', [
            'id' => $world['vehicle']->id, 'plate' => 'KDY 599G', 'seat' => $world['seat']->name, 'status' => 1, 'merchant_short_code' => 4321075,
        ])->assertOk();
    }

    #[Test]
    public function linking_a_bus_to_a_head_office_answers_with_its_new_registration(): void
    {
        Http::fake();
        Bus::fake([RegisterVehicleTill::class]);
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '5495094');
        $second = $this->connection($world['sacco'], '3020809');
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368'])->saveQuietly();

        Sanctum::actingAs($this->makeUser(self::STAFF, $world['sacco']));
        $response = $this->putJson("/api/v1/auth/mpesa/tills/{$world['vehicle']->id}/connection", ['connection_id' => $second->id])->assertOk();

        $response->assertJsonPath('till_registration.status', TillRegistrar::PENDING)
            ->assertJsonPath('till_registration.message', TillRegistrar::MESSAGES[TillRegistrar::PENDING]);
        $this->assertArrayHasKey('registration_needed', $response->json(), 'kept for older clients');
    }

    #[Test]
    public function the_backfill_gives_old_buses_a_status_from_evidence_only(): void
    {
        Http::fake();
        $world = $this->makeWorld();
        $office = $this->connection($world['sacco'], '5495094');
        $quiet = function (array $attrs) use ($world): Vehicle {
            $bus = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
            $bus->forceFill($attrs)->saveQuietly();

            return $bus;
        };

        $collecting = $quiet(['merchant_short_code' => '1417368']);
        $bankBus = $quiet(['merchant_short_code' => '880100', 'till_number' => '299106']);
        $silent = $quiet(['merchant_short_code' => '1417370']);
        // Delivered through our own C2B receiver: mpesa_setting_id is set there only.
        (new Mpesa)->forceFill([
            'TransID' => 'UKA1B2C3D4', 'MSISDN' => '254700111222', 'TransAmount' => 50, 'TransTime' => now(),
            'FirstName' => 'A', 'LastName' => 'B', 'BusinessShortCode' => '1417368', 'mpesa_setting_id' => $office->id,
        ])->save();

        Artisan::call('tills:backfill-status');
        $this->assertNull($collecting->fresh()->till_registration_status, 'a dry run writes nothing');

        Artisan::call('tills:backfill-status', ['--write' => true]);
        $this->assertSame(TillRegistrar::REGISTERED, $collecting->fresh()->till_registration_status);
        $this->assertSame(TillRegistrar::NOT_OURS, $bankBus->fresh()->till_registration_status);
        $this->assertNull($silent->fresh()->till_registration_status, 'nothing proves it either way');
    }
}
