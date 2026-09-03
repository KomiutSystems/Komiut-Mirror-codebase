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
            'phone' => '07'.str_pad((string) $n, 8, '0', STR_PAD_LEFT),
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

        $this->getJson(self::LEADS.'?status=contacted', ['X-Partner-Key' => 'ncba-secret'])
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

    #[Test]
    public function a_lead_carries_its_account_and_consent_record(): void
    {
        // The portal shows a bank where a till should settle and, beside it,
        // what the driver was told when they agreed to be listed. Both travel
        // with the lead because the consent is the thing that makes handing
        // personal data to a third party lawful.
        $lead = $this->makeLead('komiut');
        $lead->forceFill([
            'account_number' => '1234567890',
            'consent_given_at' => now(),
            'consent_text_version' => '2026-09-03.v2',
            'consent_agent' => 'agent-77',
            'consent_ip' => '41.90.0.1',
        ])->save();

        $row = $this->withHeader('X-Partner-Key', 'ncba-secret')
            ->getJson(self::LEADS)->assertOk()->json('leads.0');

        $this->assertSame('1234567890', $row['account_number']);
        $this->assertSame('2026-09-03.v2', $row['consent_text_version']);
        $this->assertSame('agent-77', $row['consent_agent']);
        $this->assertNotNull($row['consent_given_at']);
        $this->assertSame($lead->user->sacco_id, $row['sacco_id'], 'writes are addressed by id, not by name');
    }

    #[Test]
    public function the_consent_ip_is_never_handed_to_the_partner(): void
    {
        // consent_ip sits on the same row and is incident-response data for us.
        // Every field on this payload leaves the building.
        $lead = $this->makeLead('komiut');
        $lead->forceFill(['consent_ip' => '41.90.0.1', 'consent_agent' => 'agent-77'])->save();

        $row = $this->withHeader('X-Partner-Key', 'ncba-secret')
            ->getJson(self::LEADS)->assertOk()->json('leads.0');

        $this->assertArrayNotHasKey('consent_ip', $row);
    }

    #[Test]
    public function a_lead_with_no_account_says_so_rather_than_going_missing(): void
    {
        // "No account given" and "field absent" are different facts, and the
        // portal renders them differently. The key is always present.
        $this->makeLead('komiut');

        $row = $this->withHeader('X-Partner-Key', 'ncba-secret')
            ->getJson(self::LEADS)->assertOk()->json('leads.0');

        $this->assertArrayHasKey('account_number', $row);
        $this->assertNull($row['account_number']);
    }

    #[Test]
    public function the_export_does_not_widen_when_the_screen_does(): void
    {
        // THE REGRESSION THIS PINS. row() is the screen payload and grows; the
        // CSV is personal data leaving in a file. If the export is ever changed
        // to splat row(), account numbers and consent agents start going out
        // with it silently. Widening the export must be a decision.
        $lead = $this->makeLead('komiut');
        $lead->forceFill([
            'account_number' => '1234567890',
            'consent_agent' => 'agent-77',
        ])->save();

        $csv = $this->withHeader('X-Partner-Key', 'ncba-secret')
            ->get('/api/v1/partner/bank/leads/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString('1234567890', $csv, 'account numbers must not leave in the CSV');
        $this->assertStringNotContainsString('agent-77', $csv);
        $this->assertStringContainsString('ID Number', $csv, 'the existing columns are untouched');
    }
}
