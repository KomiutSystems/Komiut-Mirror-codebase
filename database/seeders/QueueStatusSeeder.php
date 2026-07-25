<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\QueueStatus;
use Illuminate\Database\Seeder;

/**
 * Seeds the canonical queue statuses (idempotent).
 *
 * The queue lifecycle transitions reference these BY the `status` enum value:
 * addQueue/queues:join start at Pending, trips:start moves Pending->Active, and
 * completeQueue moves ->Completed. Those rows previously existed only if someone
 * created them by hand through the dashboard — so completeQueue already fails
 * closed with "No completed status found!" when Completed is missing, and
 * trips:start would hit the same trap. This makes the whole set exist on every
 * deploy. `name` is unique; `status` is the enum the code actually keys on.
 */
class QueueStatusSeeder extends Seeder
{
    /** @var array<int, array{name: string, status: string}> */
    private const STATUSES = [
        ['name' => 'Pending', 'status' => 'Pending'],
        ['name' => 'Active', 'status' => 'Active'],
        ['name' => 'Suspended', 'status' => 'Suspended'],
        ['name' => 'Cancelled', 'status' => 'Cancelled'],
        ['name' => 'Completed', 'status' => 'Completed'],
    ];

    public function run(): void
    {
        foreach (self::STATUSES as $row) {
            QueueStatus::updateOrCreate(
                ['name' => $row['name']],
                ['status' => $row['status'], 'active' => true],
            );
        }
    }
}
