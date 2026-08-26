<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\UserType;
use App\Models\BankTillRequest;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Till-request letters are SACCO data, and apply() is a money-routing change.
 *
 * BankTillRequest was brand-scoped only, on the argument that the banking
 * relationship belongs to the brand. But the routes live in the SACCO dashboard
 * group and 'Manage Bank Till Requests' is granted to FINANCE — a SACCO-tier
 * role — so the callers are exactly the SACCO staff that framing assumed away.
 *
 * With only a brand scope, find() resolved any SACCO's letter in the brand:
 * index() listed their till numbers and signatories, update() edited their
 * draft, and apply() rewrote merchant_short_code on their vehicles. That column
 * decides where a bus's takings land.
 */
final class BankTillRequestTenancyTest extends QueueTestCase
{
    private function financeUser(array $world): User
    {
        $user = $this->makeUser(['Manage Bank Till Requests'], $world['sacco']);
        $user->type = UserType::Admin;
        $user->sacco_id = $world['sacco']->id;
        $user->save();

        return $user->fresh();
    }

    private function letterFor(array $world): BankTillRequest
    {
        return BankTillRequest::withoutGlobalScopes()->create([
            'sacco_id' => $world['sacco']->id,
            'brand' => 'testing',
            'bank' => 'ncba',
            'subject' => 'Push notification service',
            'paybill' => '880100',
            'till_numbers' => ['9089491'],
            'endpoint_url' => 'https://example.test/api/confirmation/1',
            'status' => BankTillRequest::STATUS_DRAFT,
        ]);
    }

    #[Test]
    public function another_saccos_letter_is_not_listed(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        $ours = $this->letterFor($mine);
        $notOurs = $this->letterFor($theirs);

        Sanctum::actingAs($this->financeUser($mine));

        $visible = BankTillRequest::query()->pluck('id')->all();

        $this->assertContains($ours->id, $visible);
        $this->assertNotContains($notOurs->id, $visible);
    }

    #[Test]
    public function another_saccos_letter_cannot_be_fetched_by_id(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();
        $notOurs = $this->letterFor($theirs);

        Sanctum::actingAs($this->financeUser($mine));

        $this->assertNull(BankTillRequest::find($notOurs->id));
    }

    #[Test]
    public function another_saccos_letter_cannot_be_applied(): void
    {
        // apply() rewrites merchant_short_code — the column that decides where a
        // bus's money lands. Doing that inside another tenant is the sharp end.
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        $notOurs = $this->letterFor($theirs);
        $notOurs->issued_tills = [['plate' => $theirs['vehicle']->plate, 'till' => '5550001']];
        $notOurs->saveQuietly();

        $before = $theirs['vehicle']->fresh()->merchant_short_code;

        Sanctum::actingAs($this->financeUser($mine));

        $this->postJson("/api/v1/auth/bank/till-requests/{$notOurs->id}/apply")
            ->assertStatus(404);

        $this->assertSame(
            $before,
            $theirs['vehicle']->fresh()->merchant_short_code,
            'another SACCO vehicle money routing must not change'
        );
    }

    #[Test]
    public function a_sacco_can_still_work_its_own_letter(): void
    {
        $mine = $this->makeWorld();
        $ours = $this->letterFor($mine);

        Sanctum::actingAs($this->financeUser($mine));

        $this->assertNotNull(BankTillRequest::find($ours->id));
    }
}
