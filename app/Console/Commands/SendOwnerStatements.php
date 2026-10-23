<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Models\OwnerNotificationSetting;
use App\Models\OwnerStatement;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Owner\OwnedVehicles;
use App\Services\Owner\StatementBuilder;
use App\Support\BusinessDay;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Builds each owner's statement for today once their chosen time has passed.
 *
 * Runs every five minutes but writes at most one statement per owner per day —
 * the unique (owner_id, date) makes a second pass a no-op, so the cadence is
 * just how precisely it honours an owner's chosen time.
 *
 * A statement is STORED even when the push is switched off: it is what the
 * screen reads, and payments settling later must not change what was reported.
 */
class SendOwnerStatements extends Command
{
    protected $signature = 'owners:send-statements {--owner= : only this owner id} {--force : ignore statement_time}';

    protected $description = "Build and push each owner's daily statement";

    public function handle(StatementBuilder $builder, NotificationService $notifications): int
    {
        $now = Carbon::now(BusinessDay::TIMEZONE);
        $today = $now->copy()->startOfDay();

        $owners = $this->option('owner') !== null
            ? User::whereKey((int) $this->option('owner'))->get()
            : OwnedVehicles::all();

        $built = 0;
        $pushed = 0;

        foreach ($owners as $owner) {
            $settings = OwnerNotificationSetting::forOwner((int) $owner->id);

            if (! $this->option('force') && ! $this->timeHasPassed((string) $settings['statement_time'], $now)) {
                continue;
            }

            if (OwnerStatement::where('owner_id', $owner->id)->whereDate('date', $today->toDateString())->exists()) {
                continue;
            }

            $figures = $builder->build($owner, $today);

            if ($figures === null) {
                continue;
            }

            $statement = OwnerStatement::create([
                'owner_id' => $owner->id,
                'date' => $today->toDateString(),
                'totals' => $figures['totals'],
                'vehicles' => $figures['vehicles'],
                'generated_at' => $now,
            ]);
            $built++;

            if (! config('owner.push_enabled') || $settings['statement_enabled'] !== true) {
                continue;
            }

            $params = [
                'day' => $today->format('l'),
                'net' => number_format((int) $figures['totals']['net']),
                'buses' => count($figures['vehicles']),
                'trips' => (int) $figures['totals']['trips'],
                'collected' => number_format((int) $figures['totals']['collections']),
                'expenses' => number_format((int) $figures['totals']['expenses']),
            ];

            $notifications->dispatch(
                $owner,
                NotificationType::OwnerStatement,
                (string) __('notifications.owner_statement.title', $params),
                (string) __('notifications.owner_statement.body', $params),
                referenceId: $today->toDateString(),
                titleKey: 'notifications.owner_statement.title',
                messageKey: 'notifications.owner_statement.body',
                params: $params,
            );

            $statement->forceFill(['pushed_at' => $now])->save();
            $pushed++;
        }

        $this->info(sprintf('owner statements: %d built, %d pushed', $built, $pushed));

        return self::SUCCESS;
    }

    /** Has this owner's chosen HH:MM already gone by today, in Nairobi? */
    private function timeHasPassed(string $hhmm, Carbon $now): bool
    {
        [$h, $m] = array_pad(array_map('intval', explode(':', $hhmm)), 2, 0);

        return $now->greaterThanOrEqualTo($now->copy()->setTime($h, $m));
    }
}
