<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CarbonCreditType;
use App\Events\PassengerBalanceChanged;
use App\Models\CarbonCreditAccount;
use App\Models\CarbonCreditTransaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hand a passenger carbon credits by hand — a goodwill gesture, a support fix,
 * or seeding a demo account.
 *
 * Writes an `adjusted` ledger row rather than editing the balance, so the
 * passenger's history explains where the credits came from and the platform
 * totals still reconcile. A reason is required for the same reason.
 *
 * Does NOT touch progress_cents: this grants credits, it does not pretend the
 * passenger travelled.
 */
class GrantCarbonCredits extends Command
{
    protected $signature = 'carbon:grant
        {email : The passenger}
        {credits : How many, negative to take them back}
        {--reason= : Shown to the passenger in their history}
        {--dry-run : Report what would change and write nothing}';

    protected $description = 'Grant or deduct carbon credits for one passenger';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $credits = (int) $this->argument('credits');

        if ($credits === 0) {
            $this->error('Grant a non-zero number of credits.');

            return self::FAILURE;
        }

        $user = User::withoutGlobalScopes()->where('email', $email)->first();
        if ($user === null) {
            $this->error("No user with email {$email}.");

            return self::FAILURE;
        }

        $reason = (string) ($this->option('reason') ?: 'Added by Komiut');

        $apply = function () use ($user, $credits, $reason): array {
            $account = CarbonCreditAccount::firstOrCreate(
                ['user_id' => $user->id],
                ['credits' => 0, 'progress_cents' => 0, 'lifetime_spend_cents' => 0],
            );
            $account = CarbonCreditAccount::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            $before = $account->credits;
            // Never below zero: a negative balance has no meaning at redemption
            // and would silently swallow the next credits they earn.
            $after = max(0, $before + $credits);

            $account->credits = $after;
            $account->save();

            CarbonCreditTransaction::create([
                'user_id' => $user->id,
                'credits' => $after - $before,
                'type' => CarbonCreditType::Adjusted,
                'spend_cents' => 0,
                'description' => $reason,
            ]);

            return [$before, $after];
        };

        if ($this->option('dry-run')) {
            DB::beginTransaction();
            try {
                [$before, $after] = $apply();
            } finally {
                DB::rollBack();
            }
            $this->line("{$user->firstname} {$user->lastname} <{$email}>: {$before} → {$after} credits");
            $this->info('Dry run — rolled back. Nothing was written.');

            return self::SUCCESS;
        }

        [$before, $after] = DB::transaction($apply);

        // A hand-granted credit is still a balance the passenger is looking at,
        // so their app hears about it the same way an earned one does. After the
        // commit, and off the dry-run path, which writes nothing to announce.
        // The clamp at zero can leave the balance untouched — say nothing then.
        if ($after !== $before) {
            PassengerBalanceChanged::carbon(
                userId: (int) $user->id,
                credits: $after,
                delta: $after - $before,
                progressCents: (int) CarbonCreditAccount::where('user_id', $user->id)->value('progress_cents'),
                reason: CarbonCreditType::Adjusted->value,
            );
        }

        $this->info("{$user->firstname} {$user->lastname} <{$email}>: {$before} → {$after} credits. Reason: {$reason}");

        return self::SUCCESS;
    }
}
