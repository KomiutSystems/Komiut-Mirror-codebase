<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\UserType;
use App\Models\QrcodePayment;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * QRCodeApiController::getQRCodePayments — the permission was wired backwards.
 *
 *     if (! $user->can('View Transactions')) { ->where('user_id', $me) }
 *
 * with no constraint on the else branch, so HOLDING the permission REMOVED the
 * own-rows restriction and returned everything the (then unscoped) model could
 * reach. More permission produced more data by negation instead of by grant.
 *
 * The permission now WIDENS explicitly: without it you see your own payments,
 * with it you see your SACCO's — and a caller with no SACCO has no tenant to
 * widen to, so they stay on their own rows rather than falling through to the
 * superadmin-shaped unscoped read.
 */
final class QrcodePaymentsAccessTest extends QueueTestCase
{
    private const ENDPOINT = '/api/v1/auth/qrcode/payments';

    private function vehicleIn(Sacco $sacco): Vehicle
    {
        return $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
    }

    private function qr(Vehicle $vehicle, ?User $payer, float $amount): QrcodePayment
    {
        return QrcodePayment::create([
            'vehicle_id' => $vehicle->id,
            'user_id' => $payer?->id,
            'amount' => $amount,
            'status' => true,
        ]);
    }

    #[Test]
    public function without_the_permission_a_caller_sees_only_their_own_payments(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->vehicleIn($sacco);
        $me = $this->makeUser([], $sacco);
        $someoneElse = $this->makeUser([], $sacco);

        $mine = $this->qr($vehicle, $me, 90);
        $theirs = $this->qr($vehicle, $someoneElse, 310);

        Sanctum::actingAs($me);
        $rows = $this->getJson(self::ENDPOINT)->assertOk()->json('payments');

        $ids = array_column($rows, 'id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    #[Test]
    public function with_the_permission_the_widened_view_stops_at_the_callers_sacco(): void
    {
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        $myVehicle = $this->vehicleIn($mine);
        $theirVehicle = $this->vehicleIn($theirs);

        $me = $this->makeUser(['View Transactions'], $mine);
        $colleague = $this->makeUser([], $mine);

        $ownRow = $this->qr($myVehicle, $me, 90);
        $colleaguesRow = $this->qr($myVehicle, $colleague, 120);
        $otherSaccosRow = $this->qr($theirVehicle, $this->makeUser([], $theirs), 400);

        Sanctum::actingAs($me);
        $rows = $this->getJson(self::ENDPOINT)->assertOk()->json('payments');
        $ids = array_column($rows, 'id');

        // Widened: a colleague's payment on my SACCO's bus is now visible...
        $this->assertContains($ownRow->id, $ids);
        $this->assertContains($colleaguesRow->id, $ids);
        // ...but the widening is bounded by the tenant, not unbounded.
        $this->assertNotContains($otherSaccosRow->id, $ids, 'The permission must not produce an unscoped read.');
    }

    #[Test]
    public function a_saccoless_caller_holding_the_permission_still_sees_only_their_own(): void
    {
        // SaccoScope does not apply to a user with no home SACCO, so before this
        // the else-branch handed a passenger holding 'View Transactions' every
        // QR payment on the platform.
        $sacco = $this->makeSacco();
        $vehicle = $this->vehicleIn($sacco);
        $passenger = $this->makeUser(['View Transactions']);   // no sacco
        $someoneElse = $this->makeUser([], $sacco);

        $mine = $this->qr($vehicle, $passenger, 90);
        $theirs = $this->qr($vehicle, $someoneElse, 310);

        Sanctum::actingAs($passenger);
        $ids = array_column($this->getJson(self::ENDPOINT)->assertOk()->json('payments'), 'id');

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    #[Test]
    public function a_saccoless_caller_cannot_widen_by_naming_a_sacco(): void
    {
        // ?sacco used to be applied BEFORE the ownership branch. It narrows the
        // set the branch settled on; it can never pick a tenant.
        $sacco = $this->makeSacco();
        $vehicle = $this->vehicleIn($sacco);
        $passenger = $this->makeUser(['View Transactions']);
        $theirs = $this->qr($vehicle, $this->makeUser([], $sacco), 310);

        Sanctum::actingAs($passenger);
        $ids = array_column($this->getJson(self::ENDPOINT.'?sacco='.$sacco->id)->assertOk()->json('payments'), 'id');

        $this->assertNotContains($theirs->id, $ids);
    }

    #[Test]
    public function a_superadmin_is_not_restricted_to_their_own_rows(): void
    {
        // A superadmin has no sacco_id either — the fail-closed rule for
        // saccoless callers must not catch them.
        $sacco = $this->makeSacco();
        $vehicle = $this->vehicleIn($sacco);
        $row = $this->qr($vehicle, $this->makeUser([], $sacco), 310);

        $super = $this->makeUser(['View Transactions']);
        $super->forceFill(['type' => UserType::Superadmin])->save();

        Sanctum::actingAs($super);
        $ids = array_column($this->getJson(self::ENDPOINT)->assertOk()->json('payments'), 'id');

        $this->assertContains($row->id, $ids);
    }

    #[Test]
    public function the_payer_payload_carries_no_roles_or_gender(): void
    {
        // The screen prints a name next to an amount. It used to eager-load
        // 'user.roles' and 'user.gender' as well — a payer's RBAC role list is
        // an access-control fact about a person, not payment data.
        $sacco = $this->makeSacco();
        $vehicle = $this->vehicleIn($sacco);
        $me = $this->makeUser([], $sacco);
        $this->qr($vehicle, $me, 90);

        Sanctum::actingAs($me);
        $rows = $this->getJson(self::ENDPOINT)->assertOk()->json('payments');

        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('user', $rows[0]);
        $this->assertArrayNotHasKey('roles', $rows[0]['user']);
        $this->assertArrayNotHasKey('gender', $rows[0]['user']);
    }
}
