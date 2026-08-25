<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * NCBA confirmation webhook authentication.
 *
 * NCBA posts M-Pesa settlement confirmations with a bank-issued Username/Password.
 * These moved out of hard-coded source into config (services.ncba.*): the endpoint
 * must record a payment only for the configured credentials, reject anything else,
 * and fail closed when unconfigured — including the old committed credentials.
 */
final class NcbaWebhookAuthTest extends QueueTestCase
{
    private const URL = '/api/testing/rest/mpesa/confirmation_new';

    private function payload(array $override = []): array
    {
        return array_merge([
            'Username' => 'ncbauser', 'Password' => 'ncbapass',
            'TransID' => 'TX1', 'TransAmount' => 500, 'BusinessShortCode' => '880100',
            'TransTime' => '20260724120000', 'Mobile' => '254700111222',
            'BillRefNumber' => '948948', 'Name' => 'John Doe',
        ], $override);
    }

    private function vehicleForShortcode(): void
    {
        $world = $this->makeWorld();
        $world['vehicle']->merchant_short_code = '880100';
        $world['vehicle']->save();
    }

    #[Test]
    public function it_records_a_confirmation_with_the_configured_credentials(): void
    {
        config(['services.ncba.username' => 'ncbauser', 'services.ncba.password' => 'ncbapass']);
        $this->vehicleForShortcode();

        $this->call('POST', self::URL, $this->payload(['TransID' => 'REAL1']));

        $this->assertDatabaseHas('mpesas', ['TransID' => 'REAL1']);
    }

    #[Test]
    public function the_brand_less_url_from_ncbas_letter_reaches_the_handler(): void
    {
        // NCBA is provisioned to POST to `komiut.com/api/rest/mpesa/confirmation_new`
        // — no brand segment, brand resolved from the host.
        //
        // That path ALSO parses as brand="rest" against the `{brand}` prefix
        // group carrying `mpesa/confirmation_new`, because "rest" is a fine
        // `[a-z]+` match. While that group was registered first it captured this
        // URL, failed to resolve a brand called "rest", and returned 404 — so in
        // production every confirmation NCBA sent to the address in their own
        // letter was rejected before reaching any handler. Verified against the
        // deployed environment, not just inferred.
        config(['services.ncba.username' => 'ncbauser', 'services.ncba.password' => 'ncbapass']);
        $this->vehicleForShortcode();

        $this->call('POST', '/api/rest/mpesa/confirmation_new', $this->payload(['TransID' => 'BRANDLESS1']))
            ->assertStatus(200);

        $this->assertDatabaseHas('mpesas', ['TransID' => 'BRANDLESS1']);
    }

    #[Test]
    public function the_branded_url_still_works_alongside_it(): void
    {
        // The branded form carries one more path segment, so moving the
        // brand-less route above the group must not shadow it.
        config(['services.ncba.username' => 'ncbauser', 'services.ncba.password' => 'ncbapass']);
        $this->vehicleForShortcode();

        $this->call('POST', self::URL, $this->payload(['TransID' => 'BRANDED1']))
            ->assertStatus(200);

        $this->assertDatabaseHas('mpesas', ['TransID' => 'BRANDED1']);
    }

    #[Test]
    public function it_rejects_wrong_credentials(): void
    {
        config(['services.ncba.username' => 'ncbauser', 'services.ncba.password' => 'ncbapass']);
        $this->vehicleForShortcode();

        $this->call('POST', self::URL, $this->payload(['Username' => 'attacker', 'Password' => 'guess', 'TransID' => 'FAKE1']));

        $this->assertDatabaseMissing('mpesas', ['TransID' => 'FAKE1']);
    }

    #[Test]
    public function it_fails_closed_when_unconfigured_and_rejects_the_old_hardcoded_creds(): void
    {
        config(['services.ncba.username' => null, 'services.ncba.password' => null]);
        $this->vehicleForShortcode();

        $this->call('POST', self::URL, $this->payload([
            'Username' => 'komiut', 'Password' => 'komiut@#234user!!', 'TransID' => 'OLD1',
        ]));

        $this->assertDatabaseMissing('mpesas', ['TransID' => 'OLD1']);
    }
}
