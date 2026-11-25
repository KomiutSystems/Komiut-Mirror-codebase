<?php

declare(strict_types=1);

namespace Tests\Feature\Bank;

use App\Auth\Roles;
use App\Enums\Financier;
use App\Models\Cash;
use App\Models\Mpesa;
use App\Models\Queue;
use App\Models\Sacco;
use App\Models\Summary;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A bank sees the SACCOs its buses belong to, and nothing that says another
 * bank exists.
 *
 * The shape that makes it hard: NICCO MOVERS banks with both NCBA and Co-op,
 * and MetroTrans with Co-op alone. Each bank must see NICCO -- with its own
 * buses only -- and only Co-op may see MetroTrans.
 */
final class BankSeesItsSaccosNotOtherBanksTest extends QueueTestCase
{
    private const LIST_URL = '/api/v1/auth/vehicles';

    private const SACCOS_URL = '/api/v1/auth/bank/saccos';

    private Sacco $nicco;

    private Sacco $metro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nicco = $this->makeSacco();
        $this->metro = $this->makeSacco();
        $seat = $this->makeSeat();
        $owner = $this->makeUser([], $this->nicco);

        foreach ([[$this->nicco, Financier::Ncba, 'KDN001N'], [$this->nicco, Financier::Ncba, 'KDN002N'],
            [$this->nicco, Financier::Coop, 'KDC003C'], [$this->metro, Financier::Coop, 'KDW322J'],
            [$this->metro, null, 'KDW088J']] as [$sacco, $bank, $plate]) {
            $v = $this->makeVehicle($sacco, $owner, $seat);
            $v->forceFill(['plate' => $plate, 'financier' => $bank?->value, 'ncba_till' => '1111', 'coop_till' => '01101233137021'])->save();
            $this->money($v, $plate);
        }
    }

    /** A day's takings on the bus: a summary, an M-Pesa payment and a cash fare. */
    private function money(Vehicle $vehicle, string $plate): void
    {
        Summary::create([
            'vehicle_id' => $vehicle->id, 'mpesa_amount' => 100, 'cash_amount' => 200, 'mpesa_txn' => 1, 'cash_txn' => 1,
            'expense_fee_amount' => '0', 'trans_date' => today(),
        ]);
        $mpesa = Mpesa::create([
            'TransID' => $plate.'-TX', 'MSISDN' => '254700111222', 'TransAmount' => 100, 'TransTime' => now(),
            'FirstName' => 'Mpesa', 'LastName' => 'Payer', 'BusinessShortCode' => '5557936',
        ]);
        Transaction::create(['mpesa_id' => $mpesa->id, 'vehicle_id' => $vehicle->id, 'amount' => 100, 'trans_date' => now()]);
        Cash::create([
            'trans_id' => $plate.'-CASH', 'vehicle_id' => $vehicle->id, 'firstname' => 'Cash', 'lastname' => 'Payer',
            'recieved_amount' => 200, 'fare_amount' => 200, 'luggage_amount' => 0, 'change_amount' => 0, 'total_amount' => 200,
            'trans_date' => now(),
        ]);
    }

    private function bank(Financier $financier): User
    {
        $user = $this->makeUser(['View Vehicles'], null);
        Role::findOrCreate(Roles::BANK_VIEWER, 'web');
        $user->assignRole(Roles::BANK_VIEWER);
        $user->forceFill(['financier' => $financier->value])->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    #[Test]
    public function each_bank_lists_the_saccos_of_its_own_buses_with_its_own_counts(): void
    {
        Sanctum::actingAs($this->bank(Financier::Coop));
        $this->assertEqualsCanonicalizing([
            ['id' => $this->nicco->id, 'name' => $this->nicco->name, 'vehicles' => 1],
            ['id' => $this->metro->id, 'name' => $this->metro->name, 'vehicles' => 1],
        ], $this->getJson(self::SACCOS_URL)->assertOk()->json('saccos'));

        Sanctum::actingAs($this->bank(Financier::Ncba));
        $this->assertSame(
            [['id' => $this->nicco->id, 'name' => $this->nicco->name, 'vehicles' => 2]],
            $this->getJson(self::SACCOS_URL)->assertOk()->json('saccos'),
        );
    }

    #[Test]
    public function the_saccos_list_is_for_banks_only(): void
    {
        Sanctum::actingAs($this->makeUser(['View Vehicles'], $this->nicco));

        $this->getJson(self::SACCOS_URL)->assertForbidden();
    }

    #[Test]
    public function a_bank_sees_each_bus_and_its_sacco_but_no_bank_names(): void
    {
        Sanctum::actingAs($this->bank(Financier::Coop));

        $response = $this->getJson(self::LIST_URL)->assertOk();
        $this->assertSame(2, $response->json('total'));
        $response->assertJsonMissingPath('financier_counts');

        foreach ($response->json('vehicles') as $vehicle) {
            $this->assertArrayNotHasKey('financier', $vehicle);
            $this->assertArrayNotHasKey('ncba_till', $vehicle);
            $this->assertArrayHasKey('coop_till', $vehicle);
            $this->assertNotNull($vehicle['sacco']['name'] ?? null, 'the SACCO each bus belongs to is shown');
        }
        $this->assertStringNotContainsStringIgnoringCase('ncba', (string) $response->getContent());

        // Filtering by one of its SACCOs narrows to that SACCO's buses of this bank.
        $this->assertSame(1, $this->getJson(self::LIST_URL.'?sacco='.$this->nicco->id)->json('total'));
    }

    #[Test]
    public function no_money_screen_names_another_bank_or_carries_a_bank_field(): void
    {
        $viewer = $this->bank(Financier::Coop);
        $viewer->givePermissionTo(Permission::findOrCreate('View Summaries', 'web'),
            Permission::findOrCreate('View Transactions', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($viewer->fresh());

        foreach (['/api/v1/auth/summaries', '/api/v1/auth/transactions', '/api/v1/auth/transactions/mpesa',
            '/api/v1/auth/transactions/cash', '/api/v1/auth/vehicles'] as $url) {
            $body = (string) $this->getJson($url)->assertOk()->getContent();

            $this->assertStringNotContainsStringIgnoringCase('ncba', $body, $url);
            $this->assertStringNotContainsString('"financier"', $body, $url);
            $this->assertStringNotContainsString('KDN00', $body, $url.': the other bank\'s buses');
            $this->assertStringContainsString('KDC003C', $body, $url.': its own buses are there');
        }
    }

    #[Test]
    public function a_bank_reads_only_the_trips_of_its_own_buses(): void
    {
        $world = $this->makeWorld();
        $active = $this->makeQueueStatus('Active', 'Active');
        $mine = $other = null;
        foreach (Vehicle::withoutGlobalScopes()->whereIn('plate', ['KDC003C', 'KDN001N', 'KDW088J'])->get() as $bus) {
            $q = $this->makeQueue($bus, $world['terminus'], $world['route'], $active, $world['owner'], 'Q-'.$bus->plate);
            $bus->plate === 'KDC003C' ? $mine = $q : $other = $q;
        }

        Sanctum::actingAs($this->bank(Financier::Coop));

        $this->assertSame([$mine->id], Queue::query()->pluck('id')->all());
        $this->assertNull(Queue::query()->find($other->id));
    }

    #[Test]
    public function a_bank_cannot_learn_the_other_banks_name_from_the_filter(): void
    {
        Sanctum::actingAs($this->bank(Financier::Coop));

        // Ignored rather than validated: the validation error listed every bank.
        $response = $this->getJson(self::LIST_URL.'?financier=bogus')->assertOk();
        $this->assertSame(2, $response->json('total'));
        $this->assertStringNotContainsStringIgnoringCase('ncba', (string) $response->getContent());
    }

    #[Test]
    public function the_passenger_catalogue_is_closed_to_banks_and_other_trips_are_not_found(): void
    {
        $world = $this->makeWorld();
        $ncbaBus = Vehicle::withoutGlobalScopes()->where('plate', 'KDN001N')->first();
        $trip = $this->makeQueue($ncbaBus, $world['terminus'], $world['route'], $this->makeQueueStatus('Active', 'Active'), $world['owner'], 'Q-N');
        Sanctum::actingAs($this->bank(Financier::Coop));

        $this->getJson('/api/v1/auth/book_a_ride/route_saccos')->assertForbidden();
        $this->getJson('/api/v1/auth/book_a_ride/routes')->assertForbidden();
        $this->getJson('/api/v1/auth/book_a_ride/seats?bus_id='.$ncbaBus->id.'&id='.$trip->id)->assertNotFound();
    }

    #[Test]
    public function everyone_else_still_sees_the_bank_of_each_bus(): void
    {
        Sanctum::actingAs($this->makeUser(['View Vehicles'], $this->nicco));

        $response = $this->getJson(self::LIST_URL)->assertOk();
        $this->assertArrayHasKey('financier_counts', $response->json());
        $this->assertEqualsCanonicalizing(['NCBA', 'NCBA', 'coop-bank'], array_column($response->json('vehicles'), 'financier'));
    }
}
