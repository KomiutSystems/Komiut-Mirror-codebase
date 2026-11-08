<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Enums\WalletEntryKind;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use App\Support\Shillings;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The wallet endpoints, against the contract the mobile app already ships.
 *
 * The app's wallet screens are BUILT -- 770 lines of wallet_data.dart behind
 * UnbuiltFeatures.wallet, which is off by default. So this suite is written
 * against that file's parser rather than against what seemed natural to return:
 * `{"wallet": {...}}`, `balance` and `tap_limit` as whole-shilling integers,
 * `top_up_sources` as a list of {id, available}, the ledger under
 * `transactions` with `current_page` / `last_page` beside it, and refusals
 * carrying the `code` the screens switch on.
 *
 * Covers WalletController: show / transactions / setPin / updateSettings.
 */
final class WalletApiTest extends QueueTestCase
{
    private const WALLET = '/api/v1/auth/wallet';

    private const LEDGER = '/api/v1/auth/wallet/transactions';

    private const PIN = '/api/v1/auth/wallet/pin';

    private const SETTINGS = '/api/v1/auth/wallet/settings';

    private function passenger(): User
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        return $user;
    }

    private function service(): WalletService
    {
        return app(WalletService::class);
    }

    #[Test]
    public function the_wallet_is_unreachable_without_a_token(): void
    {
        // No actingAs. A money endpoint that answers an anonymous caller is the
        // one failure worth a dedicated test.
        $this->getJson(self::WALLET)->assertUnauthorized();
        $this->getJson(self::LEDGER)->assertUnauthorized();
        $this->postJson(self::PIN, ['pin' => '2846'])->assertUnauthorized();
        $this->patchJson(self::SETTINGS, ['tap_limit' => 200, 'pin' => '2846'])->assertUnauthorized();
    }

    #[Test]
    public function a_passenger_who_has_never_used_the_wallet_reads_as_not_activated(): void
    {
        $this->passenger();

        $body = $this->getJson(self::WALLET)->assertOk()->json('wallet');

        $this->assertSame('not_activated', $body['status']);
        $this->assertSame(0, $body['balance']);
        $this->assertFalse($body['pin_set']);
        $this->assertSame('KES', $body['currency']);

        // AND NO ROW WAS WRITTEN. A read that creates a wallet would make every
        // passenger who merely opened the screen a float-bearing account in the
        // reconciliation sweep.
        $this->assertSame(0, Wallet::count(), 'GET /wallet must not create a wallet');
    }

    #[Test]
    public function the_limits_come_from_the_server_not_the_client(): void
    {
        // The app ships its own fallbacks (50 / 20000 / 50000 / 300). They are a
        // cold-start convenience; a client cannot be trusted to enforce a money
        // limit, so the server states them and re-checks them.
        config()->set('wallet.limits', [
            'min_top_up' => 20,
            'max_top_up' => 5000,
            'max_balance' => 9000,
            'max_tap_limit' => 150,
        ]);

        $this->passenger();

        $this->getJson(self::WALLET)->assertOk()->assertJsonPath('wallet.limits', [
            'min_top_up' => 20,
            'max_top_up' => 5000,
            'max_balance' => 9000,
            'max_tap_limit' => 150,
        ]);
    }

    #[Test]
    public function top_up_sources_are_reported_in_the_shape_the_app_filters_on(): void
    {
        // wallet_data.dart keeps only entries whose `available` is true and
        // reads `id`. A different shape silently yields NO sources, which the
        // screen renders as a wallet that cannot be topped up at all.
        config()->set('wallet.top_up_sources', [
            ['id' => 'mpesa', 'available' => true],
            ['id' => 'ncba', 'available' => false],
        ]);

        $this->passenger();

        $this->getJson(self::WALLET)->assertOk()->assertJsonPath('wallet.top_up_sources', [
            ['id' => 'mpesa', 'available' => true],
            ['id' => 'ncba', 'available' => false],
        ]);
    }

    #[Test]
    public function setting_a_pin_activates_the_wallet(): void
    {
        $this->passenger();

        $body = $this->postJson(self::PIN, ['pin' => '2846'])->assertOk()->json('wallet');

        $this->assertSame('active', $body['status'], 'setting a PIN is the activation step');
        $this->assertTrue($body['pin_set']);

        // The hash must never appear in a response, however the body is built.
        $this->assertStringNotContainsString('pin_hash', $this->getJson(self::WALLET)->getContent());
    }

    #[Test]
    public function an_obvious_pin_is_refused(): void
    {
        $this->passenger();

        foreach (['0000', '1111', '1234', '4321'] as $weak) {
            $this->postJson(self::PIN, ['pin' => $weak])
                ->assertStatus(422)
                ->assertJsonPath('code', 'weak_pin');
        }

        $this->assertSame(0, Wallet::whereNotNull('pin_hash')->count());
    }

    #[Test]
    public function a_short_pin_is_rejected_and_leading_zeros_survive(): void
    {
        $this->passenger();

        $this->postJson(self::PIN, ['pin' => '123'])->assertStatus(422)->assertJsonPath('code', 'invalid_pin');

        // digits:4 rather than a numeric size rule, so "0012" is a four-digit
        // PIN and not the number twelve.
        $this->postJson(self::PIN, ['pin' => '0012'])->assertOk();
    }

    #[Test]
    public function changing_a_pin_needs_the_current_one(): void
    {
        $this->passenger();
        $this->postJson(self::PIN, ['pin' => '2846'])->assertOk();

        $this->postJson(self::PIN, ['pin' => '9375'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'wrong_pin');

        $this->postJson(self::PIN, ['pin' => '9375', 'current_pin' => '0000'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'wrong_pin');

        $this->postJson(self::PIN, ['pin' => '9375', 'current_pin' => '2846'])->assertOk();
    }

    #[Test]
    public function a_wrong_pin_reports_how_many_tries_are_left_and_then_locks(): void
    {
        config()->set('wallet.pin.max_attempts', 3);

        $this->passenger();
        $this->postJson(self::PIN, ['pin' => '2846'])->assertOk();

        // The app renders "2 tries left" from this field, so it has to count
        // down truthfully rather than being a constant.
        $this->postJson(self::PIN, ['pin' => '9375', 'current_pin' => '0000'])
            ->assertStatus(422)->assertJsonPath('attempts_left', 2);

        $this->postJson(self::PIN, ['pin' => '9375', 'current_pin' => '0000'])
            ->assertStatus(422)->assertJsonPath('attempts_left', 1);

        $locked = $this->postJson(self::PIN, ['pin' => '9375', 'current_pin' => '0000'])
            ->assertStatus(422)->assertJsonPath('code', 'pin_locked');

        $this->assertNotNull($locked->json('locked_until'), 'the app shows the time it unlocks');

        // And the lockout holds even for the RIGHT PIN -- otherwise it is not a
        // lockout, it is a hint that the last guess was wrong.
        $this->postJson(self::PIN, ['pin' => '9375', 'current_pin' => '2846'])
            ->assertStatus(422)->assertJsonPath('code', 'pin_locked');
    }

    #[Test]
    public function a_correct_pin_clears_the_failed_attempts(): void
    {
        config()->set('wallet.pin.max_attempts', 3);

        $this->passenger();
        $this->postJson(self::PIN, ['pin' => '2846'])->assertOk();

        $this->postJson(self::PIN, ['pin' => '9375', 'current_pin' => '0000'])->assertStatus(422);
        $this->postJson(self::PIN, ['pin' => '9375', 'current_pin' => '2846'])->assertOk();

        // Without the clear, two stale wrong tries plus one genuine typo later
        // would lock a passenger out of their own money.
        $this->postJson(self::PIN, ['pin' => '5128', 'current_pin' => '0000'])
            ->assertStatus(422)->assertJsonPath('attempts_left', 2);
    }

    #[Test]
    public function the_tap_limit_cannot_be_changed_without_the_pin(): void
    {
        $this->passenger();

        // No PIN set at all: the ceiling cannot be raised.
        $this->patchJson(self::SETTINGS, ['tap_limit' => 200, 'pin' => '2846'])
            ->assertStatus(422)->assertJsonPath('code', 'pin_required');

        $this->postJson(self::PIN, ['pin' => '2846'])->assertOk();

        $this->patchJson(self::SETTINGS, ['tap_limit' => 200, 'pin' => '0000'])
            ->assertStatus(422)->assertJsonPath('code', 'wrong_pin');

        $this->patchJson(self::SETTINGS, ['tap_limit' => 200, 'pin' => '2846'])
            ->assertOk()->assertJsonPath('wallet.tap_limit', 200);
    }

    #[Test]
    public function the_tap_limit_is_capped_server_side(): void
    {
        config()->set('wallet.limits.max_tap_limit', 300);

        $this->passenger();
        $this->postJson(self::PIN, ['pin' => '2846'])->assertOk();

        $this->patchJson(self::SETTINGS, ['tap_limit' => 5000, 'pin' => '2846'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_tap_limit')
            ->assertJsonPath('max_tap_limit', 300);

        $this->assertSame(
            Shillings::toCents((int) config('wallet.default_tap_limit')),
            (int) Wallet::first()->tap_limit_cents,
            'the refused value must not have been written',
        );
    }

    #[Test]
    public function the_ledger_pages_in_the_shape_the_app_reads(): void
    {
        $user = $this->passenger();
        $svc = $this->service();

        $svc->credit((int) $user->id, Shillings::toCents(1000), WalletEntryKind::TopUp, 'top-1', [
            'title' => 'M-Pesa top-up',
        ]);

        for ($i = 1; $i <= 24; $i++) {
            $svc->debit((int) $user->id, Shillings::toCents(10), WalletEntryKind::Fare, "fare-{$i}");
        }

        $first = $this->getJson(self::LEDGER)->assertOk();

        // Paged.fromJson reads these two keys and nothing else for pagination.
        $this->assertSame(1, $first->json('current_page'));
        $this->assertSame(2, $first->json('last_page'), '25 rows at 20 per page');
        $this->assertCount(20, $first->json('transactions'));

        $newest = $first->json('transactions.0');

        $this->assertSame('fare', $newest['kind']);
        $this->assertSame('debit', $newest['direction']);
        $this->assertSame(10, $newest['amount'], 'a magnitude, never negative');
        $this->assertArrayHasKey('balance_after', $newest);
        $this->assertArrayHasKey('reference', $newest);
        $this->assertArrayHasKey('created_at', $newest);

        $second = $this->getJson(self::LEDGER.'?page=2')->assertOk();
        $this->assertCount(5, $second->json('transactions'));

        // The oldest row is the top-up, and it keeps the title it was written
        // with rather than being relabelled at render time.
        $oldest = $second->json('transactions.4');
        $this->assertSame('top_up', $oldest['kind']);
        $this->assertSame('credit', $oldest['direction']);
        $this->assertSame('M-Pesa top-up', $oldest['title']);
    }

    #[Test]
    public function a_passenger_with_no_wallet_gets_an_empty_ledger_rather_than_an_error(): void
    {
        $this->passenger();

        // The screen is reachable before the wallet exists, and an error there
        // reads to the passenger as money having gone missing.
        $this->getJson(self::LEDGER)->assertOk()->assertJsonPath('transactions', []);
    }

    #[Test]
    public function a_passenger_only_ever_sees_their_own_ledger(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser();

        $this->service()->credit((int) $alice->id, Shillings::toCents(900), WalletEntryKind::TopUp, 'alice-1');
        $this->service()->credit((int) $bob->id, Shillings::toCents(100), WalletEntryKind::TopUp, 'bob-1');

        Sanctum::actingAs($bob);

        $rows = $this->getJson(self::LEDGER)->assertOk()->json('transactions');

        $this->assertCount(1, $rows);
        $this->assertSame(100, $rows[0]['amount']);
        $this->assertSame(100, $this->getJson(self::WALLET)->json('wallet.balance'));
    }

    #[Test]
    public function this_month_counts_fares_and_nothing_else(): void
    {
        $user = $this->passenger();
        $svc = $this->service();

        $svc->credit((int) $user->id, Shillings::toCents(1000), WalletEntryKind::TopUp, 'top-1');
        $svc->debit((int) $user->id, Shillings::toCents(150), WalletEntryKind::Fare, 'fare-1');
        $svc->debit((int) $user->id, Shillings::toCents(50), WalletEntryKind::Fare, 'fare-2');
        $svc->credit((int) $user->id, Shillings::toCents(50), WalletEntryKind::Refund, 'refund-1');

        $month = $this->getJson(self::WALLET)->assertOk()->json('wallet.this_month');

        // A top-up is not spending and a refund is not a fare. Counting either
        // would overstate what the passenger spent on travel.
        $this->assertSame(200, $month['spent']);
        $this->assertSame(2, $month['fares']);
    }
}
