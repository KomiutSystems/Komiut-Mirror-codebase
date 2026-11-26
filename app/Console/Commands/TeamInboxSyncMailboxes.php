<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Team\TeamMailbox;
use App\Services\Team\Inbox\Addresses;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Make the team_mailboxes table match config('team_inbox.mailboxes').
 *
 * The addresses are settled by product and DNS, not in the console, so they
 * live in the environment (TEAM_INBOX_MAILBOXES) and this applies them:
 * listed mailboxes are created or updated and switched on; a mailbox no
 * longer listed is switched OFF, never deleted -- its threads are the team's
 * history with those customers.
 */
class TeamInboxSyncMailboxes extends Command
{
    protected $signature = 'team:inbox-sync-mailboxes {--dry-run : Show what would change without writing}';

    protected $description = 'Create, update or switch off the team inbox mailboxes to match config/team_inbox.php';

    private const BRANDS = ['komiut', 'safiri'];

    public function handle(): int
    {
        $wanted = [];
        foreach ((array) config('team_inbox.mailboxes') as $i => $entry) {
            $address = is_array($entry) ? Addresses::normalise($entry['address'] ?? null) : null;
            if ($address === null || Addresses::tag($address) !== null) {
                $this->error("Mailbox #{$i}: not a plain email address -- skipped.");

                continue;
            }

            // The console's spelling of the brand is accepted too.
            $brand = ($entry['brand'] ?? null) === '2safiri' ? 'safiri' : ($entry['brand'] ?? null);
            if ($brand !== null && ! in_array($brand, self::BRANDS, true)) {
                $this->error("{$address}: brand must be komiut, safiri or null -- skipped.");

                continue;
            }

            $wanted[$address] = [
                'name' => Addresses::line((string) ($entry['name'] ?? $address), 120) ?: $address,
                'brand' => $brand,
            ];
        }

        $dry = (bool) $this->option('dry-run');
        $existing = TeamMailbox::query()->get()->keyBy(fn (TeamMailbox $m) => mb_strtolower($m->address));
        $rows = [];

        DB::transaction(function () use ($wanted, $existing, $dry, &$rows): void {
            foreach ($wanted as $address => $attrs) {
                $mailbox = $existing->get($address) ?? new TeamMailbox(['address' => $address]);
                $mailbox->fill($attrs + ['is_active' => true]);
                $change = ! $mailbox->exists ? 'created' : ($mailbox->isDirty() ? 'updated' : 'unchanged');
                $rows[] = [$address, $attrs['name'], $attrs['brand'] ?? '-', $change];
                if (! $dry && $change !== 'unchanged') {
                    $mailbox->save();
                }
            }

            foreach ($existing as $address => $mailbox) {
                if (isset($wanted[$address]) || ! $mailbox->is_active) {
                    continue;
                }
                $rows[] = [$address, $mailbox->name, $mailbox->brand ?? '-', 'switched off'];
                if (! $dry) {
                    $mailbox->forceFill(['is_active' => false])->save();
                }
            }
        });

        $this->table(['Address', 'Name', 'Brand', $dry ? 'Would be' : 'Result'], $rows);

        return self::SUCCESS;
    }
}
