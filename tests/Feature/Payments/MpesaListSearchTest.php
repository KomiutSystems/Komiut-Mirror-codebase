<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\UserType;
use App\Models\Mpesa;
use App\Models\Sacco;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The dashboard's M-Pesa list search (MpesaAPIController::search). The query
 * changes shape with how many payments the named buses took -- receipt and
 * payer name through the trigram indexes, plus the buses' payments as a
 * UNION or, for many, as before -- and every shape must answer the same: a
 * payment matches by receipt, payer name, its bus's plate, or its SACCO's name,
 * and only inside the date range.
 */
final class MpesaListSearchTest extends QueueTestCase
{
    /** @return array{0: Vehicle, 1: User, 2: Sacco} */
    private function world(): array
    {
        $world = $this->makeWorld();

        $world['sacco']->forceFill(['name' => 'Umoja Riders'])->save();
        $world['vehicle']->forceFill(['plate' => 'KDX 434C', 'merchant_short_code' => '4560051'])->save();

        $admin = $this->makeUser(['View Transactions'], $world['sacco']);
        $admin->forceFill(['type' => UserType::Admin, 'sacco_id' => $world['sacco']->id])->save();

        return [$world['vehicle']->fresh(), $admin->fresh(), $world['sacco']->fresh()];
    }

    private function payment(?Vehicle $bus, string $receipt, string $first, string $last, ?string $at = null, ?string $transDate = null): Mpesa
    {
        $at ??= now()->toDateTimeString();

        $mpesa = Mpesa::withoutGlobalScopes()->forceCreate([
            'TransID' => $receipt,
            'TransAmount' => '50',
            'TransTime' => $at,
            'MSISDN' => '254712345678',
            'FirstName' => $first,
            'LastName' => $last,
            'BusinessShortCode' => '4560051',
        ]);

        if ($bus !== null) {
            Transaction::withoutGlobalScopes()->create([
                'vehicle_id' => $bus->id,
                'mpesa_id' => $mpesa->id,
                'amount' => 50,
                'trans_date' => $transDate ?? $at,
            ]);
        }

        return $mpesa;
    }

    /** @return list<string> */
    private function search(string $term): array
    {
        $body = $this->getJson('/api/v1/auth/transactions/mpesa?date='.now()->toDateString().'&search='.urlencode($term))
            ->assertOk()->json();

        $receipts = array_column($body['mpesa'], 'TransID');
        sort($receipts);

        $this->assertSame(count($receipts), $body['total'], 'the pager must count what the page lists');

        return $receipts;
    }

    public static function terms(): array
    {
        return [
            'part of a receipt' => ['HVKV4', ['UHVKV4WUSE']],
            'payer first name, any case' => ['joyce', ['UHVKV4WUSE']],
            'payer last name' => ['Otieno', ['UJ8439S4TY']],
            'the bus plate, typed loosely' => ['kdx-434c', ['UHVKV4WUSE', 'UJ8439S4TY']],
            'the SACCO name' => ['umoja', ['UHVKV4WUSE', 'UJ8439S4TY']],
            'nothing that exists' => ['ZZZNOTHING', []],
        ];
    }

    #[Test]
    #[DataProvider('terms')]
    public function it_finds_payments_by_receipt_name_plate_and_sacco(string $term, array $expected): void
    {
        [$bus, $admin] = $this->world();
        $this->payment($bus, 'UHVKV4WUSE', 'Joyce', 'Wanjiru');
        $this->payment($bus, 'UJ8439S4TY', 'Brian', 'Otieno');

        Sanctum::actingAs($admin);

        $this->assertSame($expected, $this->search($term));
    }

    #[Test]
    public function a_match_outside_the_date_range_is_not_listed(): void
    {
        [$bus, $admin] = $this->world();
        $this->payment($bus, 'UHVKV4WUSE', 'Joyce', 'Wanjiru');
        $this->payment($bus, 'UJ1111AAAA', 'Joyce', 'Kamau', now()->subDays(3)->toDateTimeString());

        Sanctum::actingAs($admin);

        $this->assertSame(['UHVKV4WUSE'], $this->search('joyce'));
        $this->assertSame(['UHVKV4WUSE'], $this->search('KDX434C'));
    }

    #[Test]
    public function a_bus_payment_whose_trans_date_drifted_a_little_is_still_found_by_plate(): void
    {
        [$bus, $admin] = $this->world();
        // Paid just after midnight, transaction stamped a few minutes before:
        // the plate half bounds trans_date with a day's margin, the exact range
        // is the payment's own TransTime.
        $this->payment($bus, 'UHVKV4WUSE', 'Joyce', 'Wanjiru', now()->startOfDay()->addMinutes(2)->toDateTimeString(), now()->startOfDay()->subMinutes(5)->toDateTimeString());

        Sanctum::actingAs($admin);

        $this->assertSame(['UHVKV4WUSE'], $this->search('KDX 434C'));
    }

    #[Test]
    public function another_saccos_payments_never_appear_whatever_the_term(): void
    {
        [$bus, $admin] = $this->world();
        $this->payment($bus, 'UHVKV4WUSE', 'Joyce', 'Wanjiru');

        $other = $this->makeWorld();
        $other['vehicle']->forceFill(['plate' => 'KDX 999Z'])->save();
        $this->payment($other['vehicle']->fresh(), 'UJ2222BBBB', 'Joyce', 'Achieng');

        Sanctum::actingAs($admin);

        $this->assertSame(['UHVKV4WUSE'], $this->search('joyce'));
        $this->assertSame(['UHVKV4WUSE'], $this->search('KDX'));
    }
}
