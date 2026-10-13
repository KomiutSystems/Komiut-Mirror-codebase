<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Mpesa;
use App\Models\MpesaPaymentSetting;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Mpesa\MpesaCredentialResolver;
use App\Services\Mpesa\PaymentConnectionMap;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A SACCO's M-Pesa connections: every head-office Daraja app it collects
 * through, visible and manageable by the SACCO itself, secrets write-only.
 *
 * NICCO collects through ~25 of them. Until 2026-10-08 a SACCO admin could see
 * and edit exactly one (mpesa/settings), so onboarding a till under any other
 * head office meant asking Komiut to do it by hand.
 */
final class MpesaConnectionsTest extends QueueTestCase
{
    private const LIST = '/api/v1/auth/mpesa/connections';

    /** The legacy payments server's app ids, PullMpesaTransactions::LEGACY_URL_APP. */
    private const LEGACY_3020809 = 28;

    private const LEGACY_5502888 = 15;

    private function confirmation(string $receipt, string $store, int $urlId, ?string $at = null): Mpesa
    {
        return Mpesa::forceCreate(['TransID' => $receipt, 'MSISDN' => 'x', 'TransAmount' => 50,
            'TransTime' => $at ?? now('Africa/Nairobi')->format('Y-m-d H:i:s'), 'FirstName' => 'A', 'BusinessShortCode' => $store,
            'TransactionType' => 'Customer Merchant Payment', 'mpesa_setting_id' => $urlId]);
    }

    private function admin(Sacco $sacco, array $permissions = ['Add Payment Settings', 'Edit Payment Settings', 'View Payment Settings']): User
    {
        return $this->makeUser($permissions, $sacco);
    }

    private function connection(Sacco $sacco, string $shortCode, array $extra = []): MpesaPaymentSetting
    {
        return MpesaPaymentSetting::withoutGlobalScopes()->create(array_merge([
            'sacco_id' => $sacco->id, 'name' => 'HO '.$shortCode, 'business_short_code' => $shortCode,
            'consumer_key' => 'ck-'.$shortCode, 'consumer_secret' => 'cs-'.$shortCode, 'pass_key' => 'pk-'.$shortCode,
            'payment_mode' => 'CustomerBuyGoodsOnline', 'is_live' => true, 'status' => true,
        ], $extra));
    }

    private function bus(Sacco $sacco, string $plate, string $store): Vehicle
    {
        $v = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $v->update(['plate' => $plate, 'till_number' => '1'.$store, 'merchant_short_code' => $store]);

        return $v->fresh();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Mr Mburu-Coop', 'business_short_code' => '3020809', 'payment_mode' => 'buygoods',
            'consumer_key' => 'KEY-SECRET-VALUE', 'consumer_secret' => 'SECRET-SECRET-VALUE', 'pass_key' => 'PASS-SECRET-VALUE',
        ], $overrides);
    }

    #[Test]
    public function a_sacco_sees_all_its_connections_with_usage_and_never_a_secret(): void
    {
        $nicco = $this->makeSacco();
        $default = $this->connection($nicco, '7071220');
        $mburu = $this->connection($nicco, '3020809', ['name' => 'Mr Mburu-Coop']);
        $this->connection($this->makeSacco(), '5555555'); // another SACCO's

        $bus = $this->bus($nicco, 'KDY599G', '1277149');
        $bus->forceFill(['mpesa_payment_setting_id' => $mburu->id, 'till_registered_at' => now(), 'till_registered_url' => 'https://api.komiut.com/api/confirmation/'.$mburu->id])->save();
        // One through our own registration of 1277149, one from a till the
        // legacy payments server registered: URL id 28 is ITS app for head
        // office 3020809, which is Mr Mburu-Coop here, whatever our #28 is.
        $this->bus($nicco, 'KDW927D', '3020821');
        $this->confirmation('UJ8TEST001', '1277149', $mburu->id);
        $this->confirmation('UJ8TEST00L', '3020821', self::LEGACY_3020809);
        // Another SACCO's till under the same head office is not NICCO's traffic.
        $this->bus($this->makeSacco(), 'KZZ111Z', '3020899');
        $this->confirmation('UJ8TESTOTH', '3020899', self::LEGACY_3020809);

        Sanctum::actingAs($this->admin($nicco));
        $res = $this->getJson(self::LIST)->assertOk();

        $this->assertSame(2, $res->json('count'));
        $this->assertSame($default->id, $res->json('connections.0.id'), 'the default comes first');
        $this->assertTrue($res->json('connections.0.is_default'));

        $row = collect($res->json('connections'))->firstWhere('id', $mburu->id);
        $this->assertSame('Mr Mburu-Coop', $row['name']);
        $this->assertFalse($row['is_default']);
        $this->assertSame(['consumer_key_set' => true, 'consumer_secret_set' => true, 'pass_key_set' => true], $row['credentials']);
        $this->assertTrue($row['can_take_app_payments']);
        $this->assertSame(1, $row['vehicles_count']);
        $this->assertSame(1, $row['registered_tills_count']);
        $this->assertSame(2, $row['payments_7d']);
        $this->assertNotNull($row['last_payment_at']);

        foreach (['ck-3020809', 'cs-3020809', 'pk-3020809', 'ck-7071220'] as $secret) {
            $this->assertStringNotContainsString($secret, $res->getContent(), 'no credential value ever leaves the API');
        }
    }

    #[Test]
    public function adding_a_connection_stores_the_secrets_encrypted_and_echoes_none(): void
    {
        $sacco = $this->makeSacco();
        $this->connection($sacco, '7071220');

        Sanctum::actingAs($this->admin($sacco));
        $res = $this->postJson(self::LIST, $this->payload())->assertCreated()
            ->assertJsonPath('connection.name', 'Mr Mburu-Coop')
            ->assertJsonPath('connection.business_short_code', '3020809')
            ->assertJsonPath('connection.is_default', false)
            ->assertJsonPath('connection.environment', 'live');
        $this->assertStringNotContainsString('SECRET-VALUE', $res->getContent());

        $stored = DB::table('mpesa_payment_settings')->where('business_short_code', '3020809')->first();
        $this->assertNotSame('KEY-SECRET-VALUE', $stored->consumer_key, 'encrypted at rest');
        $this->assertSame('SECRET-SECRET-VALUE', MpesaPaymentSetting::withoutGlobalScopes()->find($stored->id)->consumer_secret);
        $this->assertSame($sacco->id, (int) $stored->sacco_id);
    }

    #[Test]
    public function a_passkey_is_optional_but_without_one_it_cannot_take_app_payments(): void
    {
        $sacco = $this->makeSacco();
        Sanctum::actingAs($this->admin($sacco));

        $this->postJson(self::LIST, $this->payload(['pass_key' => null]))->assertCreated()
            ->assertJsonPath('connection.can_register_tills', true)
            ->assertJsonPath('connection.can_take_app_payments', false);
    }

    #[Test]
    public function one_short_code_one_connection_across_the_platform(): void
    {
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        $existing = $this->connection($mine, '3020809');
        $this->connection($theirs, '4564233');

        Sanctum::actingAs($this->admin($mine));
        $this->postJson(self::LIST, $this->payload())->assertStatus(409)
            ->assertJsonPath('error', "Short code 3020809 is already connected for this SACCO (connection #{$existing->id}).");
        $this->postJson(self::LIST, $this->payload(['business_short_code' => '4564233']))->assertStatus(409)
            ->assertJsonPath('error', 'Short code 4564233 is already connected on Komiut under another account. Contact Komiut support.');
    }

    #[Test]
    public function editing_keeps_a_secret_left_blank_and_replaces_one_sent(): void
    {
        $sacco = $this->makeSacco();
        $c = $this->connection($sacco, '3020809');

        Sanctum::actingAs($this->admin($sacco));
        $this->patchJson(self::LIST.'/'.$c->id, ['name' => 'Renamed', 'consumer_key' => '', 'consumer_secret' => 'NEW-SECRET'])
            ->assertOk()->assertJsonPath('connection.name', 'Renamed');

        $fresh = MpesaPaymentSetting::withoutGlobalScopes()->find($c->id);
        $this->assertSame('ck-3020809', $fresh->consumer_key, 'blank keeps the stored value');
        $this->assertSame('NEW-SECRET', $fresh->consumer_secret);
        $this->assertSame('pk-3020809', $fresh->pass_key, 'absent keeps it too');
    }

    #[Test]
    public function the_default_moves_only_when_asked_and_the_fallback_follows_it(): void
    {
        $sacco = $this->makeSacco();
        $first = $this->connection($sacco, '7071220');
        $second = $this->connection($sacco, '3020809');
        $bus = $this->bus($sacco, 'KDA001A', '4321075');

        $this->assertTrue($first->fresh()->is_default, 'a SACCO\'s first connection is its default');
        $this->assertFalse($second->fresh()->is_default, 'adding another never moves the fallback');
        $this->assertSame($first->id, MpesaCredentialResolver::settingFor($bus)->id);

        Sanctum::actingAs($this->admin($sacco));
        $this->patchJson(self::LIST.'/'.$second->id, ['make_default' => true])->assertOk()->assertJsonPath('connection.is_default', true);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertSame($second->id, MpesaCredentialResolver::settingFor($bus->fresh())->id);

        $this->patchJson(self::LIST.'/'.$second->id, ['make_default' => false])->assertStatus(422);
    }

    #[Test]
    public function an_unowned_connection_is_never_a_fallback(): void
    {
        // Imported legacy connections have no SACCO until assigned, and an
        // assigned one is not a default: no bus moves onto it by accident.
        $sacco = $this->makeSacco();
        $orphan = MpesaPaymentSetting::withoutGlobalScopes()->create([
            'business_short_code' => '4064116', 'consumer_key' => 'a', 'consumer_secret' => 'b',
            'payment_mode' => 'CustomerBuyGoodsOnline', 'is_live' => true, 'status' => true,
        ]);
        $orphan->forceFill(['sacco_id' => $sacco->id])->save();

        $this->assertNull(MpesaCredentialResolver::settingFor($this->bus($sacco, 'KCS065A', '4064116')));
    }

    #[Test]
    public function a_bus_is_linked_to_a_connection_of_its_own_sacco_only(): void
    {
        $sacco = $this->makeSacco();
        $this->connection($sacco, '7071220');
        $mburu = $this->connection($sacco, '3020809');
        $foreign = $this->connection($this->makeSacco(), '5555555');
        $bus = $this->bus($sacco, 'KDY599G', '1277149');

        Sanctum::actingAs($this->admin($sacco));
        $url = "/api/v1/auth/mpesa/tills/{$bus->id}/connection";

        $this->putJson($url, ['connection_id' => $foreign->id])->assertStatus(422);
        $this->putJson($url, ['connection_id' => $mburu->id])->assertOk()
            ->assertJsonPath('vehicle.connection_id', $mburu->id)
            ->assertJsonPath('can_take_app_payments', true);
        $this->assertSame($mburu->id, MpesaCredentialResolver::settingFor($bus->fresh())->id);

        $this->putJson($url, ['connection_id' => null])->assertOk()->assertJsonPath('vehicle.connection_id', null);
        $this->assertNull($bus->fresh()->mpesa_payment_setting_id);
    }

    #[Test]
    public function the_tills_list_shows_the_linked_connection_and_where_payments_arrive(): void
    {
        $sacco = $this->makeSacco();
        $default = $this->connection($sacco, '7071220');
        $mburu = $this->connection($sacco, '3020809', ['name' => 'Mr Mburu-Coop']);
        $bus = $this->bus($sacco, 'KDW927D', '3020821');
        $this->confirmation('UJ8TEST002', '3020821', self::LEGACY_3020809);

        Sanctum::actingAs($this->admin($sacco));
        $row = collect($this->getJson('/api/v1/auth/mpesa/tills')->assertOk()->json('tills'))->firstWhere('plate', 'KDW927D');

        $this->assertSame($default->id, $row['connection']['id'], 'unlinked: the SACCO default');
        $this->assertSame('sacco_default', $row['connection_source']);
        $this->assertSame($mburu->id, $row['receiving_via']['id'], 'but its payments arrive through Mr Mburu-Coop');
        $this->assertSame('Mr Mburu-Coop', $row['receiving_via']['name']);
    }

    #[Test]
    public function a_connection_something_depends_on_cannot_be_removed(): void
    {
        $sacco = $this->makeSacco();
        $default = $this->connection($sacco, '7071220');
        $linked = $this->connection($sacco, '3020809');
        $spare = $this->connection($sacco, '9999999');
        $this->bus($sacco, 'KDY599G', '1277149')->forceFill(['mpesa_payment_setting_id' => $linked->id])->save();

        Sanctum::actingAs($this->admin($sacco));
        $this->deleteJson(self::LIST.'/'.$default->id)->assertStatus(409);
        $this->deleteJson(self::LIST.'/'.$linked->id)->assertStatus(409);
        $this->deleteJson(self::LIST.'/'.$spare->id)->assertOk();
        $this->assertNull(MpesaPaymentSetting::withoutGlobalScopes()->find($spare->id));
    }

    #[Test]
    public function another_saccos_connection_is_invisible_and_untouchable(): void
    {
        $mine = $this->makeSacco();
        $theirs = $this->connection($this->makeSacco(), '5555555');

        Sanctum::actingAs($this->admin($mine));
        $this->getJson(self::LIST.'/'.$theirs->id)->assertNotFound();
        $this->patchJson(self::LIST.'/'.$theirs->id, ['name' => 'mine now'])->assertNotFound();
        $this->deleteJson(self::LIST.'/'.$theirs->id)->assertNotFound();
    }

    #[Test]
    public function viewing_is_not_editing(): void
    {
        $sacco = $this->makeSacco();
        $c = $this->connection($sacco, '7071220');

        Sanctum::actingAs($this->admin($sacco, ['View Payment Settings']));
        $this->getJson(self::LIST)->assertOk();
        $this->postJson(self::LIST, $this->payload())->assertForbidden();
        $this->patchJson(self::LIST.'/'.$c->id, ['name' => 'x'])->assertForbidden();
    }

    #[Test]
    public function the_ownership_command_assigns_only_on_clear_evidence(): void
    {
        $nicco = $this->makeSacco();
        $other = $this->makeSacco();
        $orphan = fn (string $sc) => MpesaPaymentSetting::withoutGlobalScopes()->create([
            'business_short_code' => $sc, 'consumer_key' => 'a', 'consumer_secret' => 'b',
            'payment_mode' => 'CustomerBuyGoodsOnline', 'is_live' => true, 'status' => true,
        ]);
        $used = $orphan('3020809');
        $unused = $orphan('3571373');
        $this->bus($nicco, 'KDW927D', '3020821')->forceFill(['mpesa_payment_setting_id' => $used->id])->save();

        $this->artisan('mpesa:assign-connection-owners')->assertSuccessful();
        $this->assertNull($used->fresh()->sacco_id, 'a dry run writes nothing');

        $this->artisan('mpesa:assign-connection-owners', ['--write' => true, '--assign' => [$unused->id.':'.$other->id]])->assertSuccessful();
        $this->assertSame($nicco->id, (int) $used->fresh()->sacco_id);
        $this->assertSame($other->id, (int) $unused->fresh()->sacco_id, 'an explicit decision is recorded');
        $this->assertFalse($used->fresh()->is_default, 'an assigned connection is never a fallback');
    }

    #[Test]
    public function a_till_registered_by_our_registrar_arrives_under_our_id_only_after_registering(): void
    {
        // A head office no legacy app ever served, so the legacy reading of
        // this connection's id can never land back on it by coincidence.
        $sacco = $this->makeSacco();
        $ours = $this->connection($sacco, '9020809', ['name' => 'Registered here']);
        $bus = $this->bus($sacco, 'KDY599G', '1277149');
        $bus->forceFill(['mpesa_payment_setting_id' => $ours->id, 'till_registered_at' => now()->subHour(),
            'till_registered_url' => 'https://api.komiut.com/api/confirmation/'.$ours->id])->save();

        // Before we registered the till, the same number on its confirmations
        // was the legacy server's app id: not ours, whatever it equals.
        $this->confirmation('UJ8TESTOLD', '1277149', $ours->id, now('Africa/Nairobi')->subHours(2)->format('Y-m-d H:i:s'))
            ->forceFill(['created_at' => now()->subHours(2)])->save();
        $this->confirmation('UJ8TESTNEW', '1277149', $ours->id);

        $map = PaymentConnectionMap::load();
        $this->assertSame($ours->id, $map->connectionOf('1277149', $ours->id, now()));
        $this->assertNull($map->connectionOf('1277149', $ours->id, now()->subHours(2)));
        $this->assertNull($map->connectionOf('5555001', $ours->id, now()), 'nor on a till we never registered');

        Sanctum::actingAs($this->admin($sacco));
        $row = collect($this->getJson(self::LIST)->assertOk()->json('connections'))->firstWhere('id', $ours->id);
        $this->assertSame(1, $row['payments_7d'], 'only the payment received after registering is ours');
    }

    #[Test]
    public function a_payment_for_a_head_office_we_hold_no_connection_for_is_named_not_a_500(): void
    {
        $sacco = $this->makeSacco();
        $this->connection($sacco, '7071220');
        $this->bus($sacco, 'KCS065A', '5502901');
        $this->confirmation('UJ8TEST003', '5502901', self::LEGACY_5502888);

        Sanctum::actingAs($this->admin($sacco));
        $row = collect($this->getJson('/api/v1/auth/mpesa/tills')->assertOk()->json('tills'))->firstWhere('plate', 'KCS065A');

        $this->assertNull($row['receiving_via']['id']);
        $this->assertSame('5502888', $row['receiving_via']['business_short_code']);
        $this->assertNotNull($row['receiving_via']['last_payment_at']);
    }

    #[Test]
    public function receiving_via_is_the_latest_payment_not_the_most_common(): void
    {
        $sacco = $this->makeSacco();
        $this->connection($sacco, '7071220');
        $mburu = $this->connection($sacco, '3020809');
        $bus = $this->bus($sacco, 'KDW927D', '3020821');
        // Weeks under another head office, then re-registered under Mr Mburu-Coop.
        foreach (range(1, 3) as $i) {
            $this->confirmation("UJ8OLD000{$i}", '3020821', self::LEGACY_5502888, now('Africa/Nairobi')->subDays(10 + $i)->format('Y-m-d H:i:s'));
        }
        $this->confirmation('UJ8NEW0001', '3020821', self::LEGACY_3020809);

        Sanctum::actingAs($this->admin($sacco));
        $row = collect($this->getJson('/api/v1/auth/mpesa/tills')->assertOk()->json('tills'))->firstWhere('plate', 'KDW927D');
        $this->assertSame($mburu->id, $row['receiving_via']['id']);
    }

    #[Test]
    public function a_caller_with_no_sacco_is_not_a_super_admin(): void
    {
        $sacco = $this->makeSacco();
        $c = $this->connection($sacco, '7071220');
        $bus = $this->bus($sacco, 'KDA001A', '4321075');

        // A bank user, an investor: payment-settings permissions, no SACCO.
        Sanctum::actingAs($this->makeUser(['Add Payment Settings', 'Edit Payment Settings', 'View Payment Settings']));

        $this->getJson(self::LIST.'?sacco_id='.$sacco->id)->assertStatus(422);
        $this->getJson(self::LIST.'/'.$c->id)->assertNotFound();
        $this->patchJson(self::LIST.'/'.$c->id, ['consumer_key' => 'stolen'])->assertNotFound();
        $this->deleteJson(self::LIST.'/'.$c->id)->assertNotFound();
        $this->postJson(self::LIST.'?sacco_id='.$sacco->id, $this->payload(['business_short_code' => '1111111']))->assertStatus(422);
        $this->putJson("/api/v1/auth/mpesa/tills/{$bus->id}/connection", ['connection_id' => null])->assertStatus(404);
        $this->getJson('/api/v1/auth/mpesa/settings?sacco_id='.$sacco->id)->assertStatus(422);
        $this->postJson('/api/v1/auth/mpesa/settings?sacco_id='.$sacco->id, ['consumer_key' => 'stolen'])->assertStatus(422);

        $this->assertSame('ck-7071220', $c->fresh()->consumer_key);
    }

    #[Test]
    public function another_saccos_buses_on_a_shared_connection_are_counted_not_shown_and_their_payments_not_changed(): void
    {
        $mine = $this->makeSacco();
        $shared = $this->connection($mine, '3020809');
        $this->bus($mine, 'KDY599G', '1277149')->forceFill(['mpesa_payment_setting_id' => $shared->id])->save();
        $this->bus($this->makeSacco(), 'KZZ999Z', '7777777')->forceFill(['mpesa_payment_setting_id' => $shared->id])->save();

        Sanctum::actingAs($this->admin($mine));
        $res = $this->getJson(self::LIST.'/'.$shared->id)->assertOk();
        $this->assertSame(['KDY599G'], array_column($res->json('vehicles'), 'plate'));
        $this->assertSame(1, $res->json('connection.vehicles_count'));
        $this->assertSame(1, $res->json('connection.other_saccos_vehicles_count'));
        $this->assertStringNotContainsString('KZZ999Z', $res->getContent());

        $this->patchJson(self::LIST.'/'.$shared->id, ['consumer_key' => 'NEW'])->assertStatus(409);
        $this->patchJson(self::LIST.'/'.$shared->id, ['enabled' => false])->assertStatus(409);
        $this->patchJson(self::LIST.'/'.$shared->id, ['name' => 'Ours too'])->assertOk();
        $this->assertSame('ck-3020809', $shared->fresh()->consumer_key);
    }

    #[Test]
    public function the_ownership_command_never_moves_a_default_or_hands_over_a_shared_connection(): void
    {
        $a = $this->makeSacco();
        $b = $this->makeSacco();
        $default = $this->connection($a, '7071220');
        $orphan = MpesaPaymentSetting::withoutGlobalScopes()->create([
            'business_short_code' => '3571373', 'consumer_key' => 'a', 'consumer_secret' => 'b',
            'payment_mode' => 'CustomerBuyGoodsOnline', 'is_live' => true, 'status' => true,
        ]);
        $this->bus($a, 'KDA001A', '4321075')->forceFill(['mpesa_payment_setting_id' => $orphan->id])->save();
        $this->bus($b, 'KDB002B', '4321076')->forceFill(['mpesa_payment_setting_id' => $orphan->id])->save();

        $this->artisan('mpesa:assign-connection-owners', ['--write' => true, '--assign' => [$default->id.':'.$b->id, $orphan->id.':'.$a->id]])
            ->assertSuccessful();

        $this->assertSame($a->id, (int) $default->fresh()->sacco_id, 'a default stays with its SACCO');
        $this->assertTrue($default->fresh()->is_default);
        $this->assertNull($orphan->fresh()->sacco_id, 'a connection two SACCOs\' buses use is left for a person');
    }

    #[Test]
    public function a_sacco_has_at_most_one_default_in_the_database(): void
    {
        $sacco = $this->makeSacco();
        $this->connection($sacco, '7071220');
        $second = $this->connection($sacco, '3020809');

        $this->expectException(QueryException::class);
        $second->forceFill(['is_default' => true])->save();
    }

    #[Test]
    public function the_old_settings_form_obeys_the_same_rules_as_connections(): void
    {
        $mine = $this->makeSacco();
        $default = $this->connection($mine, '7071220');
        $this->bus($this->makeSacco(), 'KZZ999Z', '7777777')->forceFill(['mpesa_payment_setting_id' => $default->id])->save();
        $this->connection($this->makeSacco(), '4564233');

        Sanctum::actingAs($this->admin($mine));
        $form = ['business_short_code' => '7071220', 'payment_mode' => 'buygoods', 'consumer_key' => 'NEW'];

        $this->postJson('/api/v1/auth/mpesa/settings', $form)->assertStatus(409);
        $this->assertSame('ck-7071220', $default->fresh()->consumer_key, 'another SACCO\'s buses pay through it');

        $default->forceFill(['sacco_id' => $mine->id])->save();
        Vehicle::withoutGlobalScopes()->where('mpesa_payment_setting_id', $default->id)->update(['mpesa_payment_setting_id' => null]);
        $this->postJson('/api/v1/auth/mpesa/settings', ['business_short_code' => '4564233'] + $form)->assertStatus(409)
            ->assertJsonPath('error', 'Short code 4564233 is already connected on Komiut under another account. Contact Komiut support.');
        $this->postJson('/api/v1/auth/mpesa/settings', $form)->assertOk();
        $this->assertSame('NEW', $default->fresh()->consumer_key);
    }

    #[Test]
    public function naming_a_connections_current_owner_changes_nothing(): void
    {
        $sacco = $this->makeSacco();
        $default = $this->connection($sacco, '7071220');

        $this->artisan('mpesa:assign-connection-owners', ['--write' => true, '--assign' => [$default->id.':'.$sacco->id]])->assertSuccessful();

        $this->assertTrue($default->fresh()->is_default, 'its SACCO keeps its fallback');
        $this->assertSame($default->id, MpesaCredentialResolver::settingFor($this->bus($sacco, 'KDA001A', '4321075'))->id);
    }

    #[Test]
    public function the_tills_page_names_another_saccos_connection_by_short_code_only(): void
    {
        $theirs = $this->makeSacco();
        $this->connection($theirs, '3020809', ['name' => 'Their private label']);
        $mine = $this->makeSacco();
        $this->connection($mine, '7071220');
        $this->bus($mine, 'KDW927D', '3020821');
        $this->confirmation('UJ8TEST004', '3020821', self::LEGACY_3020809);

        Sanctum::actingAs($this->admin($mine));
        $res = $this->getJson('/api/v1/auth/mpesa/tills')->assertOk();
        $row = collect($res->json('tills'))->firstWhere('plate', 'KDW927D');

        $this->assertNull($row['receiving_via']['id']);
        $this->assertSame('3020809', $row['receiving_via']['business_short_code']);
        $this->assertTrue($row['receiving_via']['other_account']);
        $this->assertStringNotContainsString('Their private label', $res->getContent());
    }
}
