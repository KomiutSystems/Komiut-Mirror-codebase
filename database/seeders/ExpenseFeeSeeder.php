<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ExpenseFee;
use Illuminate\Database\Seeder;

/**
 * The expense types a matatu crew actually records.
 *
 * Without at least one row the driver app's expense form has nothing to put in
 * its picker, and POST driver/expenses rejects every submission with "the
 * selected expense fee id is invalid" — which is what it did on production.
 *
 * sacco_id is left null: these are platform-wide defaults every SACCO can use.
 * A SACCO that wants its own category adds one against its own id, and this
 * seeder never touches it.
 *
 * Idempotent on name, so re-running after a deploy neither duplicates nor
 * resets a row somebody has since disabled.
 */
class ExpenseFeeSeeder extends Seeder
{
    /** The day-to-day costs of running a matatu on a Kenyan route. */
    private const TYPES = [
        'Fuel',
        'Parking',
        'Stage Fee',
        'Police / Fines',
        'Repairs & Maintenance',
        'Car Wash',
        'Driver Allowance',
        'Conductor Allowance',
        'SACCO Levy',
        'Insurance',
        'Other',
    ];

    public function run(): void
    {
        foreach (self::TYPES as $name) {
            ExpenseFee::firstOrCreate(
                ['name' => $name, 'sacco_id' => null],
                ['status' => true],
            );
        }
    }
}
