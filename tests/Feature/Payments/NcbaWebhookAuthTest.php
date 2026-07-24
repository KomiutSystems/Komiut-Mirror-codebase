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
