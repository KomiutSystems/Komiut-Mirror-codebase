<?php

declare(strict_types=1);

namespace Tests\Feature\Bank;

use App\Enums\Financier;
use App\Enums\UserType;
use App\Models\Sacco;
use App\Models\Seat;
use App\Models\User;
use App\Models\Vehicle;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * `vehicles.financier` as an authorization key rather than a text field.
 *
 * It decides which bank is shown a vehicle and its money. Before this it was
 * validated as 'string|nullable|max:60' and written straight through from
 * POST /auth/vehicles/add — which doubles as the EDIT endpoint whenever an
 * `id` is supplied — so any Fleet Manager or SACCO Admin could move their bus
 * out from under the bank financing it by typing in a box, or knock it out of
 * both banks' views entirely with a typo that nothing would reject.
 *
 * Two separate defences, and both are needed: an allow-list, so an accepted
 * value is always a bank we know; and a writer check, so the only accounts that
 * can reassign a vehicle's bank are the ones above every SACCO.
 */
final class VehicleFinancierWriteTest extends QueueTestCase
{
    private Sacco $sacco;

    private Seat $seat;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sacco = $this->makeSacco();
        $this->seat = $this->makeSeat();
        $this->vehicle = $this->makeVehicle($this->sacco, $this->makeUser([], $this->sacco), $this->seat);
        $this->vehicle->financier = Financier::Ncba->value;
        $this->vehicle->save();
    }

    /**
     * The edit payload the dashboard sends: everything the validator requires,
     * plus whatever financier the caller is trying to set.
     *
     * @return array<string, mixed>
     */
    private function editPayload(mixed $financier): array
    {
        return [
            'id' => $this->vehicle->id,
            'plate' => $this->vehicle->plate,
            'seat' => $this->seat->name,
            'status' => 1,
            'financier' => $financier,
        ];
    }

    private function fleetManager(): User
    {
        return $this->makeUser(['Edit Vehicles', 'Add Vehicles'], $this->sacco);
    }

    private function superadmin(): User
    {
        $user = $this->makeUser(['Edit Vehicles', 'Add Vehicles'], null);
        $user->forceFill(['type' => UserType::Superadmin])->save();

        return $user;
    }

    private function storedFinancier(): ?string
    {
        return Vehicle::withoutGlobalScopes()->findOrFail($this->vehicle->id)->financier;
    }

    #[Test]
    public function a_sacco_admin_cannot_change_which_bank_finances_a_vehicle(): void
    {
        Sanctum::actingAs($this->fleetManager());

        $this->postJson('/api/v1/auth/vehicles/add', $this->editPayload(Financier::Coop->value))
            ->assertStatus(403);

        $this->assertSame(Financier::Ncba->value, $this->storedFinancier());
    }

    #[Test]
    public function a_sacco_admin_cannot_clear_the_financier_either(): void
    {
        // Blanking it is the same attack with a quieter payload: an unfinanced
        // vehicle is invisible to BOTH banks, so the money on it stops
        // reconciling against anything.
        Sanctum::actingAs($this->fleetManager());

        $this->postJson('/api/v1/auth/vehicles/add', $this->editPayload(null))
            ->assertStatus(403);

        $this->assertSame(Financier::Ncba->value, $this->storedFinancier());
    }

    #[Test]
    public function a_sacco_admin_editing_other_fields_still_saves(): void
    {
        // The refusal is on a CHANGE, not on the field's presence. The edit
        // form round-trips whatever is stored, so 403-ing on every submission
        // that mentions financier would break ordinary fleet admin entirely.
        Sanctum::actingAs($this->fleetManager());

        $payload = $this->editPayload(Financier::Ncba->value);
        $payload['fleet_no'] = '42';

        $this->postJson('/api/v1/auth/vehicles/add', $payload)->assertOk();

        $vehicle = Vehicle::withoutGlobalScopes()->findOrFail($this->vehicle->id);
        $this->assertSame('42', $vehicle->fleet_no);
        $this->assertSame(Financier::Ncba->value, $vehicle->financier);
    }

    #[Test]
    public function a_blank_financier_on_an_unfinanced_vehicle_is_not_a_change(): void
    {
        // 11 production vehicles legitimately carry NULL. A form posting an
        // empty box against one of them is saying nothing, not asking for a
        // reassignment — and '' must not reach the allow-list as a value.
        $this->vehicle->financier = null;
        $this->vehicle->save();

        Sanctum::actingAs($this->fleetManager());

        $this->postJson('/api/v1/auth/vehicles/add', $this->editPayload(''))->assertOk();

        $this->assertNull($this->storedFinancier());
    }

    #[Test]
    public function a_superadmin_can_still_set_the_financier(): void
    {
        Sanctum::actingAs($this->superadmin());

        $this->postJson('/api/v1/auth/vehicles/add', $this->editPayload(Financier::Coop->value))
            ->assertOk();

        $this->assertSame(Financier::Coop->value, $this->storedFinancier());
    }

    #[Test]
    public function a_financier_that_is_not_a_known_bank_is_rejected(): void
    {
        // The allow-list, tested through the one caller who is allowed to
        // write the field at all — so the 400 can only be coming from
        // validation, not from the writer check.
        Sanctum::actingAs($this->superadmin());

        $this->postJson('/api/v1/auth/vehicles/add', $this->editPayload('Equity'))
            ->assertStatus(400)
            ->assertJsonPath('errors.financier.0', fn (string $message): bool => $message !== '');

        $this->assertSame(Financier::Ncba->value, $this->storedFinancier());
    }

    #[Test]
    public function a_case_variant_of_a_real_bank_is_rejected_too(): void
    {
        // 'ncba' is the kind of value the old 'string|max:60' rule accepted
        // happily, and it silently removes the bus from NCBA's dashboard: the
        // scope compares against the stored value 'NCBA' exactly.
        Sanctum::actingAs($this->superadmin());

        $this->postJson('/api/v1/auth/vehicles/add', $this->editPayload('ncba'))
            ->assertStatus(400);

        $this->assertSame(Financier::Ncba->value, $this->storedFinancier());
    }

    #[Test]
    public function a_sacco_admin_can_still_create_a_vehicle_when_the_form_posts_a_financier(): void
    {
        // The create path, which the edit tests above do not reach. On CREATE
        // there is no stored financier to defend, so refusing the submission
        // would 403 the whole request and create NO vehicle -- breaking
        // ordinary vehicle registration for any dashboard whose form posts the
        // field at all. The field is dropped instead: the bus is created, and
        // it is created unfinanced, because assigning a bank is a superadmin's
        // job. Regression guard -- this returned 403 before the fix.
        Sanctum::actingAs($this->fleetManager());

        $this->postJson('/api/v1/auth/vehicles/add', [
            'id' => 0,
            'plate' => 'KZZ999Z',
            'seat' => $this->seat->name,
            'sacco' => $this->sacco->name,
            'status' => 1,
            'financier' => Financier::Ncba->value,
        ])->assertOk();

        $created = Vehicle::withoutGlobalScopes()->where('plate', 'KZZ999Z')->first();

        $this->assertNotNull($created, 'The vehicle was not created.');
        $this->assertNull($created->financier, 'A SACCO admin must not be able to set the financier on create.');
    }

    #[Test]
    public function a_superadmin_creating_a_vehicle_may_set_the_financier(): void
    {
        // The other half: the tier that IS allowed to assign a bank can do it
        // at creation time, so a new bus does not have to be created and then
        // edited to reach the same state.
        Sanctum::actingAs($this->superadmin());

        $this->postJson('/api/v1/auth/vehicles/add', [
            'id' => 0,
            'plate' => 'KZZ888Z',
            'seat' => $this->seat->name,
            'sacco' => $this->sacco->name,
            'status' => 1,
            'financier' => Financier::Coop->value,
        ])->assertOk();

        $this->assertSame(
            Financier::Coop->value,
            Vehicle::withoutGlobalScopes()->where('plate', 'KZZ888Z')->first()?->financier,
        );
    }
}
