<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Enums\BankPartner;
use App\Models\DriverBankLead;
use App\Models\Sacco;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The partner-bank lead list.
 *
 * The security properties that matter here are: an unconfigured partner is not
 * an open door, and one bank can never read the other's drivers — the brand is
 * fixed by the key, never by anything the caller sends.
 */
final class BankLeadsTest extends QueueTestCase
{
    private const LEADS = '/api/v1/partner/bank/leads';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bank_portal.partners' => [
                'ncba' => ['password' => 'ncba-secret', 'brand' => 'komiut', 'label' => 'NCBA Bank'],
                'coop' => ['password' => 'coop-secret', 'brand' => 'safiri', 'label' => 'Co-operative Bank'],
            ],
        ]);
    }

    /** A driver with a bank lead on the given brand. */
    private function makeLead(string $brand, string $branch = 'Westlands', int $seats = 14): DriverBankLead
    {
        $n = $this->nextSequence();
        $sacco = Sacco::create(['name' => "Sacco {$brand} {$n}", 'status' => 1, 'brand' => $brand]);
        $driver = User::create([
            'firstname' => 'Driver', 'lastname' => (string) $n,
            'phone' => '07' . str_pad((string) $n, 8, '0', STR_PAD_LEFT),
            'password' => 'password', 'sacco_id' => $sacco->id, 'status' => true,
        ]);

        return DriverBankLead::create([
            'user_id' => $driver->id,
            'brand' => $brand,
            'bank' => $brand === 'komiut' ? BankPartner::Ncba : BankPartner::Coop,
            'preferred_branch' => $branch,
            'vehicle_capacity' => $seats,
            'opted_in_at' => now(),
            'status' => 'new',
        ]);
    }

    #[Test]
    public function a_partner_sees_its_own_brands_leads(): void
    {
        $this->makeLead('komiut', 'Westlands', 14);

        $this->getJson(self::LEADS, ['X-Partner-Key' => 'ncba-secret'])
            ->assertOk()
            ->assertJsonPath('partner', 'NCBA Bank')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('leads.0.preferred_branch', 'Westlands')
            ->assertJsonPath('leads.0.vehicle_seats', 14);
    }

    #[Test]
    public function a_partner_can_never_see_the_other_banks_drivers(): void
    {
        $this->makeLead('komiut');   // NCBA's
        $this->makeLead('safiri');   // Co-op's

        // Each key returns exactly one lead — its own.
        $this->getJson(self::LEADS, ['X-Partner-Key' => 'ncba-secret'])
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('partner', 'NCBA Bank');

        $this->getJson(self::LEADS, ['X-Partner-Key' => 'coop-secret'])
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('partner', 'Co-operative Bank');
    }

    #[Test]
    public function a_wrong_or_missing_key_is_rejected(): void
    {
        $this->makeLead('komiut');

        $this->getJson(self::LEADS)->assertStatus(401);
        $this->getJson(self::LEADS, ['X-Partner-Key' => 'not-the-password'])->assertStatus(401);
    }

    #[Test]
    public function an_unconfigured_partner_is_not_an_open_door(): void
    {
        // The classic failure: a blank env var becoming a blank password.
        config(['bank_portal.partners' => [
            'ncba' => ['password' => null, 'brand' => 'komiut', 'label' => 'NCBA Bank'],
        ]]);
        $this->makeLead('komiut');

        $this->getJson(self::LEADS, ['X-Partner-Key' => ''])->assertStatus(401);
        $this->getJson(self::LEADS, ['X-Partner-Key' => 'anything'])->assertStatus(401);
    }

    #[Test]
    public function leads_can_be_filtered_by_status(): void
    {
        $this->makeLead('komiut');
        $contacted = $this->makeLead('komiut');
        $contacted->update(['status' => 'contacted']);

        $this->getJson(self::LEADS . '?status=contacted', ['X-Partner-Key' => 'ncba-secret'])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('leads.0.status', 'contacted');
    }

    #[Test]
    public function the_export_streams_a_csv_of_the_same_scope(): void
    {
        $this->makeLead('komiut', 'Westlands', 33);
        $this->makeLead('safiri', 'Kisumu', 14);   // the other bank's — must not appear

        $response = $this->get('/api/v1/partner/bank/leads/export', ['X-Partner-Key' => 'ncba-secret'])
            ->assertOk();

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Preferred Branch', $csv);
        $this->assertStringContainsString('Westlands', $csv);
        $this->assertStringNotContainsString('Kisumu', $csv, 'the other bank\'s lead must not be exported');
    }
}
