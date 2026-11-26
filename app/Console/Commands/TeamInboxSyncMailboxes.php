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
 * listed mailboxes are created or updated and switched on. A mailbox no
 * longer listed is switched OFF only with --deactivate-missing, and never
 * deleted -- its threads are the team's history with those customers.
 *
 * ALL OR NOTHING. A switched-off mailbox makes InboundMail drop mail for its
 * address, answered 200 so nobody retries -- that mail is gone. So a list
 * that cannot be trusted changes nothing: JSON that does not parse, or any
 * entry that is not valid, fails the command before a single write; and an
 * empty list never switches every mailbox off, flag or not.
 */
class TeamInboxSyncMailboxes extends Command
{
    protected $signature = 'team:inbox-sync-mailboxes
        {--dry-run : Show what would change without writing}
        {--deactivate-missing : Also switch off active mailboxes that are not in the list}';

    protected $description = 'Create, update (and with --deactivate-missing switch off) the team inbox mailboxes to match config/team_inbox.php';

    private const BRANDS = ['komiut', 'safiri'];

    public function handle(): int
    {
        if (! config('team_inbox.mailboxes_valid', true)) {
            $this->error('TEAM_INBOX_MAILBOXES is not a valid JSON list -- nothing changed.');

            return self::FAILURE;
        }

        $wanted = [];
        $errors = [];
        foreach ((array) config('team_inbox.mailboxes') as $i => $entry) {
            $address = is_array($entry) ? Addresses::normalise($entry['address'] ?? null) : null;
            if ($address === null || Addresses::tag($address) !== null) {
                $errors[] = "Mailbox #{$i}: not a plain email address.";

                continue;
            }

            // The console's spelling of the brand is accepted too.
            $brand = ($entry['brand'] ?? null) === '2safiri' ? 'safiri' : ($entry['brand'] ?? null);
            if ($brand !== null && ! in_array($brand, self::BRANDS, true)) {
                $errors[] = "{$address}: brand must be komiut, safiri or null.";

                continue;
            }

            if (isset($wanted[$address])) {
                $errors[] = "{$address}: listed twice.";

                continue;
            }

            $wanted[$address] = [
                'name' => Addresses::line((string) ($entry['name'] ?? $address), 120) ?: $address,
                'brand' => $brand,
            ];
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }
            $this->error('Fix TEAM_INBOX_MAILBOXES and run again -- nothing changed.');

            return self::FAILURE;
        }

        $deactivate = (bool) $this->option('deactivate-missing');
        if ($deactivate && $wanted === []) {
            $this->error('The mailbox list is empty: refusing to switch every mailbox off -- nothing changed.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $existing = TeamMailbox::query()->get()->keyBy(fn (TeamMailbox $m) => mb_strtolower($m->address));
        $rows = [];

        DB::transaction(function () use ($wanted, $existing, $dry, $deactivate, &$rows): void {
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

                if (! $deactivate) {
                    $rows[] = [$address, $mailbox->name, $mailbox->brand ?? '-', 'not listed, left on (--deactivate-missing)'];

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
