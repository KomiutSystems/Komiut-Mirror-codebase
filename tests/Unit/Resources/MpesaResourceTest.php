<?php

declare(strict_types=1);

namespace Tests\Unit\Resources;

use App\Http\Resources\MpesaResource;
use App\Models\Mpesa;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Guards the MpesaResource contract, especially the Safaricom Daraja PascalCase
 * field names, which must NOT be snake_cased.
 */
class MpesaResourceTest extends TestCase
{
    public function test_it_exposes_pascalcase_daraja_fields(): void
    {
        $mpesa = new Mpesa([
            'TransID' => 'RKT123456',
            'MSISDN' => '254700000000',
            'TransAmount' => '150',
            'TransTime' => '2026-07-21 08:30:00',
            'FirstName' => 'Jane',
            'MiddleName' => 'A',
            'LastName' => 'Doe',
            'ThirdPartyTransID' => null,
            'InvoiceNumber' => null,
            'BillRefNumber' => '174379',
            'BusinessShortCode' => '600000',
            'TransactionType' => 'Pay Bill',
        ]);
        $mpesa->id = 5;

        $data = (new MpesaResource($mpesa))->resolve(Request::create('/'));

        foreach ([
            'id', 'TransID', 'MSISDN', 'TransAmount', 'TransTime', 'FirstName',
            'MiddleName', 'LastName', 'ThirdPartyTransID', 'InvoiceNumber',
            'BillRefNumber', 'BusinessShortCode', 'TransactionType',
            'created_at', 'updated_at',
        ] as $key) {
            $this->assertArrayHasKey($key, $data, "missing key: {$key}");
        }

        $this->assertSame('RKT123456', $data['TransID']);
        // No snake_cased leakage.
        $this->assertArrayNotHasKey('trans_id', $data);
        $this->assertArrayNotHasKey('bill_ref_number', $data);
        // Relation not loaded -> stripped.
        $this->assertArrayNotHasKey('transaction', $data);
    }
}
