<?php

namespace App\Console\Commands;

use App\Auth\Roles;
use App\Enums\UserType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off import of genders, saccos and users from the legacy Mumbai database.
 *
 * Reads a JSON export produced by scripts/export-legacy-users.sh (run against
 * komiut_latest_app) rather than talking to the legacy database directly — the
 * two live in different regions and VPCs, and a file is reviewable before it is
 * applied.
 *
 * Legacy primary keys are PRESERVED. The target tables are empty, and every
 * table we have not migrated yet (vehicles, bookings, summaries, …) references
 * users and saccos by id; keeping the ids identical is what makes those later
 * migrations a straight copy instead of a re-mapping exercise. Postgres
 * sequences are re-synced at the end, otherwise the next natural insert would
 * collide with an imported id.
 *
 * Idempotent: re-running updates the same rows (matched on id) instead of
 * duplicating. Safe to run repeatedly while the migration is being rehearsed.
 */
class ImportLegacyUsers extends Command
{
    protected $signature = 'legacy:import-users
        {--file= : Path to the JSON export}
        {--dry-run : Report what would change and write nothing}';

    protected $description = 'Import genders, saccos and users from the legacy system';

    /**
     * Legacy role name => [UserType, new spatie role or null].
     *
     * Roles deliberately left null have no equivalent in the new RBAC model
     * (App\Auth\Roles). Those users are imported so they can still sign in, but
     * with NO permissions — guessing a bundle here would silently over-grant on
     * a system that handles money. They are listed at the end of the run so the
     * right role can be assigned deliberately.
     */
    private const ROLE_MAP = [
        'User' => [UserType::Passenger, null],
        'Super Admin' => [UserType::Superadmin, Roles::SUPER_ADMIN],
        'Sacco Admin' => [UserType::Admin, Roles::SACCO_ADMIN],
        'Admin' => [UserType::Admin, Roles::SACCO_ADMIN],
        'Conductor' => [UserType::Driver, Roles::CONDUCTOR],
        'Queue Manager' => [UserType::Admin, Roles::OPERATIONS_MANAGER],
        'Accounts' => [UserType::Admin, Roles::FINANCE],
        // No equivalent in the new model — imported without permissions.
        'CB Admin' => [UserType::Admin, null],
        'Nicco Managers' => [UserType::Admin, null],
        'Investor' => [UserType::Admin, null],
        'Nicco Administrator Cashless' => [UserType::Admin, null],
    ];

    public function handle(): int
    {
        $path = (string) $this->option('file');
        if ($path === '' || ! is_readable($path)) {
            $this->error('Pass a readable --file=<export.json>.');

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! isset($data['users'])) {
            $this->error('Export does not look like a legacy dump (no "users" key).');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $roleByUser = $this->indexRoleAssignments($data['role_assignments'] ?? []);

        $this->line(sprintf(
            'Export: %d genders, %d saccos, %d users, %d role assignments.',
            count($data['genders'] ?? []),
            count($data['saccos'] ?? []),
            count($data['users']),
            count($roleByUser),
        ));

        // Refuse to run against a database that already holds users unless they
        // came from a previous run of this command. Overwriting real signups
        // with a legacy dump is not recoverable.
        $existing = DB::table('users')->count();
        $maxLegacyId = max(array_map(fn ($u) => (int) $u['id'], $data['users']));
        if ($existing > 0 && DB::table('users')->where('id', '>', $maxLegacyId)->exists()) {
            $this->error("users already contains rows created outside this import (id > {$maxLegacyId}).");
            $this->line('Refusing to run. Migrate into an empty table, or clear the new rows first.');

            return self::FAILURE;
        }

        $unmapped = [];
        $counts = ['genders' => 0, 'saccos' => 0, 'users' => 0, 'roles' => 0, 'skipped' => 0];

        $apply = function () use ($data, $roleByUser, &$unmapped, &$counts) {
            foreach ($data['genders'] ?? [] as $g) {
                DB::table('genders')->updateOrInsert(['id' => $g['id']], [
                    'name' => $g['name'],
                    'status' => true,
                    'created_at' => $g['created_at'] ?? now(),
                    'updated_at' => now(),
                ]);
                $counts['genders']++;
            }

            foreach ($data['saccos'] ?? [] as $s) {
                DB::table('saccos')->updateOrInsert(['id' => $s['id']], [
                    'name' => $s['name'],
                    'slogan' => $s['slogan'] ?? null,
                    'phone' => $s['phone'] ?? null,
                    // Legacy saccos have no email column; the new table allows null.
                    'status' => (int) ($s['status'] ?? 0),
                    'created_at' => $s['created_at'] ?? now(),
                    'updated_at' => now(),
                ]);
                $counts['saccos']++;
            }

            foreach ($data['users'] as $u) {
                $legacyRole = $roleByUser[(int) $u['id']] ?? null;
                [$type, $newRole] = self::ROLE_MAP[$legacyRole] ?? [UserType::Passenger, null];

                if ($legacyRole !== null && ! isset(self::ROLE_MAP[$legacyRole])) {
                    $unmapped[$legacyRole][] = (int) $u['id'];
                }

                DB::table('users')->updateOrInsert(['id' => $u['id']], [
                    'firstname' => $u['firstname'],
                    'lastname' => $u['lastname'],
                    'email' => $u['email'],
                    'phone' => $u['phone'],
                    'type' => $type->value,
                    // bcrypt hashes carry over unchanged — everyone keeps their password.
                    'password' => $u['password'],
                    'dob' => $u['dob'],
                    'gender_id' => $u['gender_id'],
                    'sacco_id' => $u['sacco_id'],
                    'image' => $u['image'] ?? null,
                    'status' => (bool) $u['status'],
                    'email_verified_at' => $u['email_verified_at'] ?? null,
                    'created_at' => $u['created_at'] ?? now(),
                    'updated_at' => now(),
                ]);
                $counts['users']++;

                if ($newRole !== null) {
                    $roleId = DB::table('roles')->where('name', $newRole)->value('id');
                    if ($roleId === null) {
                        $counts['skipped']++;

                        continue;
                    }
                    DB::table('model_has_roles')->updateOrInsert([
                        'role_id' => $roleId,
                        'model_type' => \App\Models\User::class,
                        'model_id' => $u['id'],
                    ], []);
                    $counts['roles']++;
                } elseif ($legacyRole !== null && $legacyRole !== 'User') {
                    $unmapped[$legacyRole][] = (int) $u['id'];
                }
            }
        };

        if ($dryRun) {
            // Run inside a transaction that is always rolled back, so the counts
            // and the unmapped list are real rather than predicted.
            DB::beginTransaction();
            try {
                $apply();
            } finally {
                DB::rollBack();
            }
            $this->report($counts, $unmapped);
            $this->info('Dry run — everything above was rolled back. Nothing was written.');

            return self::SUCCESS;
        }

        DB::transaction($apply);
        $this->resyncSequences(['genders', 'saccos', 'users']);
        $this->report($counts, $unmapped);
        $this->info('Import complete.');

        return self::SUCCESS;
    }

    /** @return array<int,string> user id => legacy role name */
    private function indexRoleAssignments(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            // A user with several legacy roles keeps the most privileged one;
            // ROLE_MAP order is least-to-most trusted for that comparison.
            $order = array_keys(self::ROLE_MAP);
            $id = (int) $r['model_id'];
            $new = (string) $r['role_name'];
            $cur = $out[$id] ?? null;
            if ($cur === null || array_search($new, $order, true) > array_search($cur, $order, true)) {
                $out[$id] = $new;
            }
        }

        return $out;
    }

    /**
     * Explicit ids do not advance a Postgres identity sequence, so without this
     * the first user created through the app would collide with an imported id.
     */
    private function resyncSequences(array $tables): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($tables as $t) {
            DB::statement("SELECT setval(pg_get_serial_sequence('{$t}', 'id'), COALESCE((SELECT MAX(id) FROM {$t}), 1))");
            $this->line("  sequence re-synced: {$t}");
        }
    }

    private function report(array $counts, array $unmapped): void
    {
        $this->newLine();
        $this->table(['what', 'rows'], collect($counts)->map(fn ($v, $k) => [$k, $v])->values()->all());

        if ($unmapped === []) {
            return;
        }
        $this->newLine();
        $this->warn('Imported WITHOUT permissions — no equivalent role in the new model:');
        foreach ($unmapped as $role => $ids) {
            $ids = array_values(array_unique($ids));
            $this->line(sprintf('  %-30s %d user(s): %s', $role, count($ids), implode(', ', array_slice($ids, 0, 20))));
        }
        $this->line('  Assign the right role to these deliberately before go-live.');
    }
}
