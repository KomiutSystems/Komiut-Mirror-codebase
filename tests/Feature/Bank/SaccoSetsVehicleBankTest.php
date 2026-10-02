<?php

declare(strict_types=1);

namespace Tests\Feature\Bank;

use App\Auth\Roles;
use App\Enums\Financier;
use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\PlatformNotification;
use App\Models\Sacco;
use App\Models\Seat;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A SACCO sets, corrects and reads which bank finances each of its buses.
 *
 * Until 'Edit Vehicle Bank' existed only a superadmin could write
 * `vehicles.financier`, and the conversation with the SACCO kept ending at "we
 * cannot tell which bus is under which bank": NICCO MOVERS runs 126 NCBA and 54
 * Co-op buses in one fleet, and 720 vehicles platform-wide carry no bank at
 * all. The SACCO is the party that knows, so its admin may now set the bank of
 * its own buses, and the fleet list says how many sit under each bank.
 *
 * The column is still an authorization key — it decides which bank is shown a
 * bus and its money — so the guard rails are what most of this file is about:
 * the permission and not 'Edit Vehicles' moves it, only inside the caller's
 * own SACCO, and every actual move leaves an audit row and a console line.
 *
 * The fixture is the NICCO shape: one SACCO with an NCBA bus, a Co-op bus and a
 * bus with no bank, plus a second SACCO's NCBA bus that must stay out of reach
 * and out of the counts.
 */
final class SaccoSetsVehicleBankTest extends QueueTestCase
{
    private const ADD_URL = '/api/v1/auth/vehicles/add';

    private const LIST_URL = '/api/v1/auth/vehicles';

    private Sacco $sacco;

    private Sacco $otherSacco;

    private Seat $seat;

    private Vehicle $ncbaBus;

    private Vehicle $coopBus;

    private Vehicle $unbankedBus;

    private Vehicle $otherSaccosBus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sacco = $this->makeSacco();
        $this->otherSacco = $this->makeSacco();
        $this->seat = $this->makeSeat();

        $owner = $this->makeUser([], $this->sacco);
        $this->ncbaBus = $this->bus($this->sacco, $owner, Financier::Ncba->value);
        $this->coopBus = $this->bus($this->sacco, $owner, Financier::Coop->value);
        $this->unbankedBus = $this->bus($this->sacco, $owner, null);

        $this->otherSaccosBus = $this->bus(
            $this->otherSacco,
            $this->makeUser([], $this->otherSacco),
            Financier::Ncba->value,
        );
    }

    private function bus(Sacco $sacco, User $owner, ?string $financier): Vehicle
    {
        $vehicle = $this->makeVehicle($sacco, $owner, $this->seat);
        $vehicle->financier = $financier;
        $vehicle->save();

        return $vehicle;
    }

    /** A SACCO Admin's relevant slice: edits vehicles AND may move their bank. */
    private function bankEditor(): User
    {
        return $this->makeUser(
            ['View Vehicles', 'Add Vehicles', 'Edit Vehicles', Roles::EDIT_VEHICLE_BANK],
            $this->sacco,
        );
    }

    /** The Fleet Manager bundle's slice: edits vehicles, may NOT move their bank. */
    private function fleetManager(): User
    {
        return $this->makeUser(['View Vehicles', 'Add Vehicles', 'Edit Vehicles'], $this->sacco);
    }

    private function superadmin(): User
    {
        $user = $this->makeUser(['View Vehicles', 'Add Vehicles', 'Edit Vehicles'], null);
        $user->forceFill(['type' => UserType::Superadmin])->save();

        return $user;
    }

    /**
     * The edit payload: what the validator requires, plus the fields under test.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function edit(Vehicle $vehicle, array $fields = []): array
    {
        return array_merge([
            'id' => $vehicle->id,
            'plate' => $vehicle->plate,
            'seat' => $this->seat->name,
            'status' => 1,
        ], $fields);
    }

    private function stored(Vehicle $vehicle): Vehicle
    {
        return Vehicle::withoutGlobalScopes()->findOrFail($vehicle->id);
    }

    private function bankChanges(): int
    {
        return AuditLog::where('action', 'vehicles.financier.changed')->count();
    }

    // ------------------------------------------------------------ the writes

    #[Test]
    public function a_sacco_admin_moves_a_bus_from_ncba_to_coop_and_it_is_audited(): void
    {
        $editor = $this->bankEditor();
        Sanctum::actingAs($editor);

        $this->postJson(self::ADD_URL, $this->edit($this->ncbaBus, ['financier' => Financier::Coop->value]))
            ->assertOk();

        $this->assertSame(Financier::Coop->value, $this->stored($this->ncbaBus)->financier);

        $audit = AuditLog::where('action', 'vehicles.financier.changed')->sole();
        $this->assertSame(Financier::Ncba->value, $audit->data['from']);
        $this->assertSame(Financier::Coop->value, $audit->data['to']);
        $this->assertSame($this->ncbaBus->id, $audit->data['vehicleId']);
        $this->assertSame($this->ncbaBus->plate, $audit->data['plate']);
        $this->assertSame($this->sacco->id, $audit->data['saccoId']);
        $this->assertFalse($audit->data['onCreate']);
        // Stamped with the SACCO, so the SACCO's own activity log can show it.
        $this->assertSame($this->sacco->id, (int) $audit->sacco_id);
        $this->assertSame((string) $editor->id, $audit->actor_id);
        $this->assertSame((string) $this->ncbaBus->id, $audit->subject_id);

        // The bus LEFT a bank: NCBA stops seeing it. That is the move a bank
        // will dispute, so it is an alert linked to its audit row.
        $note = PlatformNotification::where('event', 'vehicles.financier.changed')->sole();
        $this->assertSame('alert', $note->delivery_class);
        $this->assertSame('high', $note->severity);
        $this->assertSame($audit->id, $note->audit_id);
        $this->assertSame(Financier::Coop->value, $note->data['to']);
    }

    #[Test]
    public function a_sacco_admin_fills_in_the_bank_of_a_bus_that_had_none(): void
    {
        Sanctum::actingAs($this->bankEditor());

        $this->postJson(self::ADD_URL, $this->edit($this->unbankedBus, ['financier' => Financier::Ncba->value]))
            ->assertOk();

        $this->assertSame(Financier::Ncba->value, $this->stored($this->unbankedBus)->financier);

        $audit = AuditLog::where('action', 'vehicles.financier.changed')->sole();
        $this->assertNull($audit->data['from']);
        $this->assertSame(Financier::Ncba->value, $audit->data['to']);

        // Filling in the backlog is review work, not an alarm.
        $note = PlatformNotification::where('event', 'vehicles.financier.changed')->sole();
        $this->assertSame('review', $note->delivery_class);
    }

    #[Test]
    public function a_run_of_fill_ins_to_one_bank_shares_one_console_card_but_not_one_audit_row(): void
    {
        // 720 buses have no bank. A SACCO working through its share must not
        // bury the console under one card per bus — but every bus still gets
        // its own immutable record.
        $owner = $this->makeUser([], $this->sacco);
        $second = $this->bus($this->sacco, $owner, null);

        Sanctum::actingAs($this->bankEditor());

        $this->postJson(self::ADD_URL, $this->edit($this->unbankedBus, ['financier' => Financier::Ncba->value]))->assertOk();
        $this->postJson(self::ADD_URL, $this->edit($second, ['financier' => Financier::Ncba->value]))->assertOk();

        $this->assertSame(2, $this->bankChanges());

        $note = PlatformNotification::where('event', 'vehicles.financier.changed')->sole();
        $this->assertSame(2, $note->count);
    }

    #[Test]
    public function a_sacco_admin_can_clear_a_wrong_bank(): void
    {
        Sanctum::actingAs($this->bankEditor());

        $this->postJson(self::ADD_URL, $this->edit($this->ncbaBus, ['financier' => null]))->assertOk();

        $this->assertNull($this->stored($this->ncbaBus)->financier);

        $audit = AuditLog::where('action', 'vehicles.financier.changed')->sole();
        $this->assertSame(Financier::Ncba->value, $audit->data['from']);
        $this->assertNull($audit->data['to']);
    }

    #[Test]
    public function a_blank_financier_from_a_sacco_admin_also_clears_it(): void
    {
        // '' means null on this endpoint (the contract the dashboard builds
        // against): an emptied select is "no bank", not "leave it alone".
        Sanctum::actingAs($this->bankEditor());

        $this->postJson(self::ADD_URL, $this->edit($this->coopBus, ['financier' => '']))->assertOk();

        $this->assertNull($this->stored($this->coopBus)->financier);
        $this->assertSame(1, $this->bankChanges());
    }

    #[Test]
    public function an_unchanged_bank_is_not_recorded_as_a_change(): void
    {
        // The edit form round-trips the stored bank on every save. Auditing
        // that would bury the real moves under a row per fleet-number edit.
        Sanctum::actingAs($this->bankEditor());

        $this->postJson(self::ADD_URL, $this->edit($this->ncbaBus, [
            'financier' => Financier::Ncba->value,
            'fleet_no' => '77',
        ]))->assertOk();

        $stored = $this->stored($this->ncbaBus);
        $this->assertSame('77', $stored->fleet_no);
        $this->assertSame(Financier::Ncba->value, $stored->financier);
        $this->assertSame(0, $this->bankChanges());
        $this->assertSame(0, PlatformNotification::where('event', 'vehicles.financier.changed')->count());
    }

    #[Test]
    public function a_superadmins_change_is_audited_too(): void
    {
        // The audit is about the bus, not about who is trusted: a bank asking
        // why a bus left its statement needs the answer either way.
        Sanctum::actingAs($this->superadmin());

        $this->postJson(self::ADD_URL, $this->edit($this->otherSaccosBus, ['financier' => Financier::Coop->value]))
            ->assertOk();

        $audit = AuditLog::where('action', 'vehicles.financier.changed')->sole();
        $this->assertSame(Financier::Ncba->value, $audit->data['from']);
        $this->assertSame(Financier::Coop->value, $audit->data['to']);
        $this->assertSame($this->otherSacco->id, (int) $audit->sacco_id);
    }

    #[Test]
    public function edit_vehicles_alone_still_cannot_move_a_bus_between_banks(): void
    {
        Sanctum::actingAs($this->fleetManager());

        $this->postJson(self::ADD_URL, $this->edit($this->ncbaBus, [
            'financier' => Financier::Coop->value,
            'fleet_no' => 'SHOULD-NOT-LAND',
        ]))->assertStatus(403);

        $stored = $this->stored($this->ncbaBus);
        $this->assertSame(Financier::Ncba->value, $stored->financier);
        $this->assertNotSame('SHOULD-NOT-LAND', $stored->fleet_no, 'A refused request must save nothing.');
        $this->assertSame(0, $this->bankChanges());
    }

    #[Test]
    public function edit_vehicles_alone_still_saves_an_unchanged_bank(): void
    {
        Sanctum::actingAs($this->fleetManager());

        $this->postJson(self::ADD_URL, $this->edit($this->coopBus, [
            'financier' => Financier::Coop->value,
            'fleet_no' => '88',
        ]))->assertOk();

        $stored = $this->stored($this->coopBus);
        $this->assertSame('88', $stored->fleet_no);
        $this->assertSame(Financier::Coop->value, $stored->financier);
    }

    #[Test]
    public function a_sacco_admin_creating_a_bus_sets_its_bank(): void
    {
        Sanctum::actingAs($this->bankEditor());

        $this->postJson(self::ADD_URL, [
            'id' => 0,
            'plate' => 'KZB101B',
            'seat' => $this->seat->name,
            'sacco' => $this->sacco->name,
            'status' => 1,
            'financier' => Financier::Coop->value,
        ])->assertOk();

        $created = Vehicle::withoutGlobalScopes()->where('plate', 'KZB101B')->sole();
        $this->assertSame(Financier::Coop->value, $created->financier);

        $audit = AuditLog::where('action', 'vehicles.financier.changed')->sole();
        $this->assertNull($audit->data['from']);
        $this->assertSame(Financier::Coop->value, $audit->data['to']);
        $this->assertTrue($audit->data['onCreate']);
        $this->assertSame($created->id, $audit->data['vehicleId']);
    }

    #[Test]
    public function creating_a_bus_with_no_bank_is_not_a_bank_change(): void
    {
        Sanctum::actingAs($this->bankEditor());

        $this->postJson(self::ADD_URL, [
            'id' => 0,
            'plate' => 'KZB102B',
            'seat' => $this->seat->name,
            'sacco' => $this->sacco->name,
            'status' => 1,
            'financier' => null,
        ])->assertOk();

        $this->assertNull(Vehicle::withoutGlobalScopes()->where('plate', 'KZB102B')->sole()->financier);
        $this->assertSame(0, $this->bankChanges());
    }

    #[Test]
    public function a_fleet_manager_creating_a_bus_has_the_bank_dropped(): void
    {
        Sanctum::actingAs($this->fleetManager());

        $this->postJson(self::ADD_URL, [
            'id' => 0,
            'plate' => 'KZB103B',
            'seat' => $this->seat->name,
            'sacco' => $this->sacco->name,
            'status' => 1,
            'financier' => Financier::Ncba->value,
        ])->assertOk();

        $this->assertNull(Vehicle::withoutGlobalScopes()->where('plate', 'KZB103B')->sole()->financier);
        $this->assertSame(0, $this->bankChanges());
    }

    #[Test]
    public function a_sacco_admin_cannot_rebank_another_saccos_bus(): void
    {
        // The other SACCO's bus is simply not found: the lookup is scoped.
        Sanctum::actingAs($this->bankEditor());

        $this->postJson(self::ADD_URL, $this->edit($this->otherSaccosBus, ['financier' => Financier::Coop->value]))
            ->assertNotFound();

        $this->assertSame(Financier::Ncba->value, $this->stored($this->otherSaccosBus)->financier);
        $this->assertSame(0, $this->bankChanges());
    }

    #[Test]
    public function a_sacco_admin_cannot_create_a_banked_bus_in_another_sacco(): void
    {
        // Naming another SACCO is ignored: the bus is created in the caller's
        // OWN SACCO, never in theirs, and banked there under the caller's own
        // SACCO's terms.
        Sanctum::actingAs($this->bankEditor());

        $this->postJson(self::ADD_URL, [
            'id' => 0,
            'plate' => 'KZB104B',
            'seat' => $this->seat->name,
            'sacco' => $this->otherSacco->name,
            'status' => 1,
            'financier' => Financier::Ncba->value,
        ])->assertOk();

        $created = Vehicle::withoutGlobalScopes()->where('plate', 'KZB104B')->sole();
        $this->assertSame($this->sacco->id, (int) $created->sacco_id, 'Another SACCO cannot be named from inside this one.');
        $this->assertSame(
            1,
            Vehicle::withoutGlobalScopes()->where('sacco_id', $this->otherSacco->id)->count(),
            'nothing new may appear in the other SACCO',
        );
    }

    #[Test]
    public function a_bus_created_without_naming_a_sacco_lands_in_the_callers_own_with_its_bank(): void
    {
        // It used to be created with no SACCO — invisible in the caller's own
        // fleet — and the bank sent with it was silently dropped.
        Sanctum::actingAs($this->bankEditor());

        $this->postJson(self::ADD_URL, [
            'id' => 0,
            'plate' => 'KZB105B',
            'seat' => $this->seat->name,
            'status' => 1,
            'financier' => Financier::Ncba->value,
        ])->assertOk();

        $created = Vehicle::withoutGlobalScopes()->where('plate', 'KZB105B')->sole();
        $this->assertSame($this->sacco->id, (int) $created->sacco_id);
        $this->assertSame(Financier::Ncba->value, $created->financier);
    }

    #[Test]
    public function editing_cannot_move_a_bus_into_another_sacco(): void
    {
        Sanctum::actingAs($this->bankEditor());

        $this->postJson(self::ADD_URL, $this->edit($this->unbankedBus, ['sacco' => $this->otherSacco->name]))
            ->assertOk();

        $this->assertSame($this->sacco->id, (int) $this->stored($this->unbankedBus)->sacco_id);
    }

    // ----------------------------------------------- a bank the SACCO is new to

    /** A SACCO with one bus and no bank anywhere in its fleet, and its admin. */
    private function saccoNewToBanks(): array
    {
        $sacco = $this->makeSacco();
        $bus = $this->bus($sacco, $this->makeUser([], $sacco), null);
        $admin = $this->makeUser(['View Vehicles', 'Add Vehicles', 'Edit Vehicles', Roles::EDIT_VEHICLE_BANK], $sacco);

        return [$sacco, $bus, $admin];
    }

    #[Test]
    public function a_sacco_with_no_bus_under_a_bank_cannot_put_one_there(): void
    {
        // The self-registration case: POST register/sacco is public and makes
        // its caller a SACCO Admin with no approval step. That account must
        // not be able to put buses onto NCBA's portal and statement.
        [, $bus, $admin] = $this->saccoNewToBanks();
        Sanctum::actingAs($admin);

        $this->postJson(self::ADD_URL, $this->edit($bus, ['financier' => Financier::Ncba->value]))
            ->assertStatus(403)
            ->assertJsonPath('error', fn (string $message): bool => str_contains($message, 'NCBA'));

        $this->assertNull($this->stored($bus)->financier);
        $this->assertSame(0, $this->bankChanges());
    }

    #[Test]
    public function a_sacco_with_no_bus_under_a_bank_cannot_create_one_there(): void
    {
        [$sacco, , $admin] = $this->saccoNewToBanks();
        Sanctum::actingAs($admin);

        $this->postJson(self::ADD_URL, [
            'id' => 0,
            'plate' => 'KZB106B',
            'seat' => $this->seat->name,
            'sacco' => $sacco->name,
            'status' => 1,
            'financier' => Financier::Ncba->value,
        ])->assertStatus(403);

        $this->assertNull(Vehicle::withoutGlobalScopes()->where('plate', 'KZB106B')->first(), 'a refused bank saves nothing');
        $this->assertSame(0, $this->bankChanges());
    }

    #[Test]
    public function once_a_superadmin_assigns_the_first_bus_the_sacco_manages_the_rest(): void
    {
        [$sacco, $first, $admin] = $this->saccoNewToBanks();
        $second = $this->bus($sacco, $this->makeUser([], $sacco), null);

        Sanctum::actingAs($this->superadmin());
        $this->postJson(self::ADD_URL, $this->edit($first, ['financier' => Financier::Ncba->value]))->assertOk();

        Sanctum::actingAs($admin);
        $this->postJson(self::ADD_URL, $this->edit($second, ['financier' => Financier::Ncba->value]))->assertOk();

        $this->assertSame(Financier::Ncba->value, $this->stored($second)->financier);
    }

    #[Test]
    public function an_account_with_no_sacco_cannot_edit_vehicles_at_all(): void
    {
        // Vehicle and Sacco both allow cross-tenant browsing, so a tenantless
        // Fleet Manager once found ANY bus and could move it into any SACCO by
        // name — after which that SACCO's admin could re-bank it.
        $tenantless = $this->fleetManager();
        $tenantless->forceFill(['sacco_id' => null])->save();
        Sanctum::actingAs($tenantless->fresh());

        $this->postJson(self::ADD_URL, $this->edit($this->otherSaccosBus, ['sacco' => $this->sacco->name]))
            ->assertStatus(403);

        $this->assertSame($this->otherSacco->id, (int) $this->stored($this->otherSaccosBus)->sacco_id);
    }

    #[Test]
    public function the_permission_without_a_sacco_moves_nothing(): void
    {
        // Vehicle opts into cross-tenant browsing so passengers can find a
        // matatu, which means a caller with NO SACCO can look up any bus. A
        // stray grant to such an account must not become "re-bank any bus in
        // the country": the bank write checks the caller's own SACCO itself.
        $tenantless = $this->bankEditor();
        $tenantless->forceFill(['sacco_id' => null])->save();
        Sanctum::actingAs($tenantless->fresh());

        $this->postJson(self::ADD_URL, $this->edit($this->ncbaBus, ['financier' => Financier::Coop->value]))
            ->assertStatus(403);

        $this->assertSame(Financier::Ncba->value, $this->stored($this->ncbaBus)->financier);
        $this->assertSame(0, $this->bankChanges());
    }

    #[Test]
    public function an_unknown_bank_is_a_400_for_a_sacco_admin_too(): void
    {
        Sanctum::actingAs($this->bankEditor());

        $this->postJson(self::ADD_URL, $this->edit($this->ncbaBus, ['financier' => 'Equity']))
            ->assertStatus(400)
            ->assertJsonPath('errors.financier.0', fn (string $message): bool => $message !== '');

        $this->assertSame(Financier::Ncba->value, $this->stored($this->ncbaBus)->financier);
    }

    #[Test]
    public function a_console_outage_does_not_fail_the_save(): void
    {
        // The bank change is committed before the notification is raised; an
        // error there must be logged, not turned into a failure for a write
        // that happened.
        $this->app->instance(PlatformNotifier::class, new class extends PlatformNotifier
        {
            public function dispatch(PlatformEvent $event): PlatformNotification
            {
                throw new RuntimeException('console unavailable');
            }
        });

        Sanctum::actingAs($this->bankEditor());

        $this->postJson(self::ADD_URL, $this->edit($this->ncbaBus, ['financier' => Financier::Coop->value]))
            ->assertOk();

        $this->assertSame(Financier::Coop->value, $this->stored($this->ncbaBus)->financier);
        $this->assertSame(1, $this->bankChanges(), 'The audit row is written before the console is tried.');
    }

    #[Test]
    public function the_sacco_sees_the_change_in_its_own_activity_log(): void
    {
        $editor = $this->makeUser(
            ['View Vehicles', 'Edit Vehicles', Roles::EDIT_VEHICLE_BANK, 'View Activity Log'],
            $this->sacco,
        );
        Sanctum::actingAs($editor);

        $this->postJson(self::ADD_URL, $this->edit($this->ncbaBus, ['financier' => Financier::Coop->value]))
            ->assertOk();

        $rows = collect($this->getJson('/api/auth/activity')->assertOk()->json('activity'))
            ->where('action', 'vehicles.financier.changed')
            ->values();

        $this->assertCount(1, $rows);
        $this->assertStringContainsString($this->ncbaBus->plate, $rows[0]['description']);
        $this->assertStringContainsString('NCBA Bank', $rows[0]['description']);
        $this->assertStringContainsString('Co-operative Bank', $rows[0]['description']);
    }

    // ------------------------------------------------- omit means keep

    #[Test]
    public function saving_the_tills_does_not_wipe_the_fleet_number(): void
    {
        // The dashboard's payment sheet posts the tills and not fleet_no, and
        // every save there used to write fleet_no = NULL.
        $this->ncbaBus->forceFill(['fleet_no' => 'N-12', 'merchant_short_code' => 4455667])->save();
        Sanctum::actingAs($this->fleetManager());

        $this->postJson(self::ADD_URL, $this->edit($this->ncbaBus, ['till_number' => 5123456]))->assertOk();

        $stored = $this->stored($this->ncbaBus);
        $this->assertSame('N-12', $stored->fleet_no);
        $this->assertSame(5123456, (int) $stored->till_number);
        $this->assertSame(4455667, (int) $stored->merchant_short_code, 'An omitted short code is kept too.');
    }

    #[Test]
    public function saving_the_fleet_number_does_not_wipe_the_tills(): void
    {
        $this->ncbaBus->forceFill(['till_number' => 5123456, 'merchant_short_code' => 5123456])->save();
        Sanctum::actingAs($this->fleetManager());

        $this->postJson(self::ADD_URL, $this->edit($this->ncbaBus, ['fleet_no' => 'N-13']))->assertOk();

        $stored = $this->stored($this->ncbaBus);
        $this->assertSame('N-13', $stored->fleet_no);
        $this->assertSame(5123456, (int) $stored->till_number);
        $this->assertSame(5123456, (int) $stored->merchant_short_code);
    }

    #[Test]
    public function sending_a_field_as_null_still_clears_it(): void
    {
        // Omitted keeps; present-and-null clears. Clearing on purpose must
        // keep working.
        $this->ncbaBus->forceFill(['fleet_no' => 'N-14'])->save();
        Sanctum::actingAs($this->fleetManager());

        $this->postJson(self::ADD_URL, $this->edit($this->ncbaBus, ['fleet_no' => null]))->assertOk();

        $this->assertNull($this->stored($this->ncbaBus)->fleet_no);
    }

    #[Test]
    public function omitting_the_bank_keeps_it(): void
    {
        Sanctum::actingAs($this->bankEditor());

        $this->postJson(self::ADD_URL, $this->edit($this->coopBus, ['fleet_no' => 'C-1']))->assertOk();

        $this->assertSame(Financier::Coop->value, $this->stored($this->coopBus)->financier);
        $this->assertSame(0, $this->bankChanges());
    }

    // ------------------------------------------------- the list and its counts

    /** @return array<int, string> */
    private function listedPlates(string $query = ''): array
    {
        return array_column(
            $this->getJson(self::LIST_URL.$query)->assertOk()->json('vehicles'),
            'plate',
        );
    }

    #[Test]
    public function the_list_filters_by_bank(): void
    {
        Sanctum::actingAs($this->fleetManager());

        $this->assertSame([$this->ncbaBus->plate], $this->listedPlates('?financier=NCBA'));
        $this->assertSame([$this->coopBus->plate], $this->listedPlates('?financier=coop-bank'));
        $this->assertSame([$this->unbankedBus->plate], $this->listedPlates('?financier=none'));
    }

    #[Test]
    public function a_blank_bank_filter_is_no_filter(): void
    {
        Sanctum::actingAs($this->fleetManager());

        $this->assertEqualsCanonicalizing(
            [$this->ncbaBus->plate, $this->coopBus->plate, $this->unbankedBus->plate],
            $this->listedPlates('?financier='),
        );
    }

    #[Test]
    public function an_unknown_bank_filter_is_a_400(): void
    {
        Sanctum::actingAs($this->fleetManager());

        foreach (['Equity', 'ncba', 'null'] as $value) {
            $this->getJson(self::LIST_URL.'?financier='.urlencode($value))
                ->assertStatus(400)
                ->assertJsonPath('errors.financier.0', fn (string $message): bool => $message !== '');
        }
    }

    #[Test]
    public function the_counts_cover_the_sacco_and_only_the_sacco(): void
    {
        Sanctum::actingAs($this->fleetManager());

        $response = $this->getJson(self::LIST_URL)->assertOk();

        // The other SACCO's NCBA bus is not ours to count.
        $this->assertSame(['NCBA' => 1, 'coop-bank' => 1, 'none' => 1], $response->json('financier_counts'));

        // Additive: every key the list already returned is still there.
        $response->assertJsonStructure(['vehicles', 'total', 'per_page', 'current_page', 'last_page', 'financier_counts']);
        $this->assertSame(3, $response->json('total'));
    }

    #[Test]
    public function the_counts_ignore_the_bank_filter_and_the_search(): void
    {
        // The chips are built on these numbers. "NCBA 1" must still read 1
        // after the NCBA chip is clicked, and after something is typed.
        Sanctum::actingAs($this->fleetManager());

        $expected = ['NCBA' => 1, 'coop-bank' => 1, 'none' => 1];

        $filtered = $this->getJson(self::LIST_URL.'?financier=NCBA')->assertOk();
        $this->assertCount(1, $filtered->json('vehicles'));
        $this->assertSame($expected, $filtered->json('financier_counts'));

        $searched = $this->getJson(self::LIST_URL.'?search='.urlencode($this->coopBus->plate))->assertOk();
        $this->assertSame([$this->coopBus->plate], array_column($searched->json('vehicles'), 'plate'));
        $this->assertSame($expected, $searched->json('financier_counts'));
    }

    #[Test]
    public function the_counts_follow_a_bank_change_at_once(): void
    {
        // Counted live: "No bank 1" that does not drop to 0 after the save
        // reads as a save that did not land.
        Sanctum::actingAs($this->bankEditor());

        $this->assertSame(1, $this->getJson(self::LIST_URL)->json('financier_counts.none'));

        $this->postJson(self::ADD_URL, $this->edit($this->unbankedBus, ['financier' => Financier::Ncba->value]))
            ->assertOk();

        $this->assertSame(
            ['NCBA' => 2, 'coop-bank' => 1, 'none' => 0],
            $this->getJson(self::LIST_URL)->json('financier_counts'),
        );
    }

    #[Test]
    public function the_filtered_page_total_follows_a_bank_change_at_once_too(): void
    {
        // The pager's `total` used to come from a count cached for a minute,
        // so right after a save the chip said 0 while the pager still said 1.
        Sanctum::actingAs($this->bankEditor());

        $this->assertSame(1, $this->getJson(self::LIST_URL.'?financier=none')->json('total'));

        $this->postJson(self::ADD_URL, $this->edit($this->unbankedBus, ['financier' => Financier::Ncba->value]))
            ->assertOk();

        $after = $this->getJson(self::LIST_URL.'?financier=none');
        $this->assertSame(0, $after->json('total'));
        $this->assertSame(0, $after->json('financier_counts.none'));
    }

    #[Test]
    public function a_bank_user_counts_only_its_own_bank(): void
    {
        // FinancierScope applies to the counts exactly as to the list: NCBA's
        // viewer is shown NCBA's buses across SACCOs and nothing of Co-op's.
        $bank = $this->makeUser(['View Vehicles'], null);
        Role::findOrCreate(Roles::BANK_VIEWER, 'web');
        $bank->assignRole(Roles::BANK_VIEWER);
        $bank->financier = Financier::Ncba->value;
        $bank->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($bank->fresh());

        $this->assertSame(
            ['NCBA' => 2, 'coop-bank' => 0, 'none' => 0],
            $this->getJson(self::LIST_URL)->assertOk()->json('financier_counts'),
        );
    }

    #[Test]
    public function a_superadmin_narrowed_to_one_sacco_counts_that_sacco(): void
    {
        $super = $this->superadmin();
        Sanctum::actingAs($super);

        $this->assertSame(
            ['NCBA' => 2, 'coop-bank' => 1, 'none' => 1],
            $this->getJson(self::LIST_URL)->assertOk()->json('financier_counts'),
            'Unnarrowed, a superadmin counts every SACCO.',
        );

        $this->assertSame(
            ['NCBA' => 1, 'coop-bank' => 0, 'none' => 0],
            $this->getJson(self::LIST_URL.'?sacco='.$this->otherSacco->id)->assertOk()->json('financier_counts'),
        );
    }

    // ------------------------------------------------- who holds the permission

    #[Test]
    public function the_seeder_gives_the_permission_to_sacco_admin_and_not_to_fleet_manager(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertTrue(Role::findByName(Roles::SACCO_ADMIN, 'web')->hasPermissionTo(Roles::EDIT_VEHICLE_BANK));
        $this->assertTrue(Role::findByName(Roles::SUPER_ADMIN, 'web')->hasPermissionTo(Roles::EDIT_VEHICLE_BANK));

        foreach ([Roles::FLEET_MANAGER, Roles::INVESTOR, Roles::OPERATIONS_MANAGER, Roles::FINANCE, Roles::BANK_VIEWER] as $role) {
            $this->assertFalse(
                Role::findByName($role, 'web')->hasPermissionTo(Roles::EDIT_VEHICLE_BANK),
                "{$role} must not be able to move a bus between banks.",
            );
        }
    }

    #[Test]
    public function a_sacco_admin_by_role_can_move_the_bank(): void
    {
        // End to end through the real role rather than a direct grant.
        $this->seed(RoleSeeder::class);

        $admin = $this->makeUser([], $this->sacco);
        $admin->assignRole(Roles::SACCO_ADMIN);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($admin->fresh());

        $this->postJson(self::ADD_URL, $this->edit($this->coopBus, ['financier' => Financier::Ncba->value]))
            ->assertOk();

        $this->assertSame(Financier::Ncba->value, $this->stored($this->coopBus)->financier);
    }
}
