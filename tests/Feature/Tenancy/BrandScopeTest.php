<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\UserType;
use App\Models\Sacco;
use App\Models\Seat;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cross-brand isolation. A single shared DB holds both brands' operational data;
 * the request's brand (set into Context by ResolveBrand) must confine each app
 * to its own SACCOs and fleet. Passengers stay global — they are not brand-owned.
 */
final class BrandScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Context::flush();
        parent::tearDown();
    }

    #[Test]
    public function saccos_are_confined_to_the_current_brand(): void
    {
        $komiut = Sacco::create(['name' => 'K', 'status' => 1, 'brand' => 'komiut']);
        $safiri = Sacco::create(['name' => 'S', 'status' => 1, 'brand' => 'safiri']);

        Context::add('brand', 'komiut');

        $ids = Sacco::all()->pluck('id');
        $this->assertTrue($ids->contains($komiut->id));
        $this->assertFalse($ids->contains($safiri->id), 'komiut app must not list safiri SACCOs.');
        $this->assertNull(Sacco::find($safiri->id), 'find() must not resolve another brand\'s SACCO.');
    }

    #[Test]
    public function vehicles_are_confined_to_the_current_brand(): void
    {
        $vk = $this->vehicleIn('komiut');
        $vs = $this->vehicleIn('safiri');

        Context::add('brand', 'komiut');

        $this->assertNotNull(Vehicle::find($vk->id));
        $this->assertNull(Vehicle::find($vs->id), 'A safiri vehicle must be invisible in the komiut app.');
    }

    #[Test]
    public function a_super_admin_sees_across_brands(): void
    {
        $komiut = Sacco::create(['name' => 'K', 'status' => 1, 'brand' => 'komiut']);
        $safiri = Sacco::create(['name' => 'S', 'status' => 1, 'brand' => 'safiri']);

        Context::add('brand', 'komiut'); // the /super console still sends an X-App-Key

        // A normal (brand-bound) user sees only the active brand.
        $this->actingAs(User::factory()->create());
        $this->assertNull(Sacco::find($safiri->id));

        // The super admin — the platform role — is above the brand boundary.
        $admin = User::factory()->create();
        $admin->forceFill(['type' => UserType::Superadmin])->save();
        $this->actingAs($admin);

        $ids = Sacco::all()->pluck('id');
        $this->assertTrue($ids->contains($komiut->id));
        $this->assertTrue($ids->contains($safiri->id), 'A super admin must see every brand.');
        $this->assertNotNull(Sacco::find($safiri->id));
    }

    #[Test]
    public function one_sacco_may_span_brands_and_each_app_sees_only_its_own(): void
    {
        // Production shape: NICCO MOVERS LIMITED runs Co-op-financed buses next
        // to NCBA ones. The SACCO's own brand must not decide what its fleet
        // belongs to — vehicles.brand is authoritative.
        $sacco = Sacco::create(['name' => 'NICCO MOVERS LIMITED', 'status' => 1, 'brand' => 'komiut']);
        $owner = User::factory()->create(['sacco_id' => $sacco->id]);
        $seat = Seat::create(['name' => 'Shared', 'seats' => 14, 'rows' => 4, 'columns' => 4, 'status' => true]);

        $ncba = Vehicle::create([
            'plate' => 'KDX447K', 'fleet_no' => '1', 'sacco_id' => $sacco->id,
            'user_id' => $owner->id, 'seat_id' => $seat->id, 'status' => true, 'brand' => 'komiut',
        ]);
        $coop = Vehicle::create([
            'plate' => 'KDR027C', 'fleet_no' => '2', 'sacco_id' => $sacco->id,
            'user_id' => $owner->id, 'seat_id' => $seat->id, 'status' => true, 'brand' => 'safiri',
        ]);

        Context::add('brand', 'komiut');
        $this->assertNotNull(Vehicle::find($ncba->id));
        $this->assertNull(Vehicle::find($coop->id), 'A Co-op bus must be invisible in the komiut app.');

        Context::flush();
        Context::add('brand', 'safiri');
        $this->assertNotNull(Vehicle::find($coop->id));
        $this->assertNull(
            Vehicle::find($ncba->id),
            'An NCBA bus must be invisible in the 2safiri app even though its SACCO is shared.'
        );
    }

    #[Test]
    public function without_an_active_brand_nothing_is_scoped(): void
    {
        // Console commands / non-brand requests operate on the whole DB.
        $k = Sacco::create(['name' => 'K', 'status' => 1, 'brand' => 'komiut']);
        $s = Sacco::create(['name' => 'S', 'status' => 1, 'brand' => 'safiri']);

        $this->assertSame(2, Sacco::whereIn('id', [$k->id, $s->id])->count());
    }

    #[Test]
    public function a_sacco_created_in_a_brand_context_is_auto_stamped(): void
    {
        Context::add('brand', 'safiri');

        $sacco = Sacco::create(['name' => 'Auto', 'status' => 1]);

        $this->assertSame('safiri', $sacco->brand);
    }

    private function vehicleIn(string $brand): Vehicle
    {
        $sacco = Sacco::create(['name' => "S-{$brand}", 'status' => 1, 'brand' => $brand]);
        $owner = User::factory()->create(['sacco_id' => $sacco->id]);
        $seat = Seat::create(['name' => "Standard-{$brand}", 'seats' => 14, 'rows' => 4, 'columns' => 4, 'status' => true]);

        return Vehicle::create([
            'plate' => strtoupper($brand).'001',
            'fleet_no' => '1',
            'sacco_id' => $sacco->id,
            'user_id' => $owner->id,
            'seat_id' => $seat->id,
            'status' => true,
            'brand' => $brand,
        ]);
    }
}
