<?php

namespace App\Console\Commands;

use App\Auth\Roles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Second migration slice: SACCO membership, crew assignments, and the role
 * backfill for users the first slice could not map.
 *
 * legacy:import-users set users.sacco_id, which is what SaccoScope reads — but
 * the members screen lists `sacco_users` and the crew screen lists
 * `vehicle_users`, and neither was migrated. Both screens rendered empty even
 * though the users themselves were present.
 *
 * Legacy ids are preserved and the Postgres sequences re-synced, same rule as
 * the first slice.
 *
 * The role pass exists because the four legacy roles the first import refused
 * to guess at (Investor, Nicco Managers, Nicco Administrator Cashless, CB
 * Admin) now HAVE equivalents in App\Auth\Roles, defined from the exact
 * permission rows they carried in the old system. Those 37 users can finally be
 * given their access.
 */
class ImportLegacyMemberships extends Command
{
    protected $signature = 'legacy:import-memberships
        {--file= : Path to the JSON export}
        {--dry-run : Report what would change and write nothing}';

    protected $description = 'Import sacco_users, vehicle_users and backfill roles';

    /** Legacy role name => role name in this system. */
    private const ROLE_MAP = [
        'User' => null,
        'Super Admin' => Roles::SUPER_ADMIN,
        'Sacco Admin' => Roles::SACCO_ADMIN,
        'Admin' => Roles::SACCO_ADMIN,
        'Conductor' => Roles::CONDUCTOR,
        'Queue Manager' => Roles::OPERATIONS_MANAGER,
        'Accounts' => Roles::FINANCE,
        'Investor' => Roles::INVESTOR,
        'Nicco Managers' => Roles::QUEUE_SUPERVISOR,
        'Nicco Administrator Cashless' => Roles::CASHLESS_ADMIN,
        'CB Admin' => Roles::BANK_VIEWER,
    ];

    public function handle(): int
    {
        $path = (string) $this->option('file');
        if ($path === '' || ! is_readable($path)) {
            $this->error('Pass a readable --file=<export.json>.');

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            $this->error('Export is not valid JSON.');

            return self::FAILURE;
        }

        $saccoUsers = $data['sacco_users'] ?? [];
        $vehicleUsers = $data['vehicle_users'] ?? [];
        $assignments = $data['role_assignments'] ?? [];

        $this->line(sprintf(
            'Export: %d sacco_users, %d vehicle_users, %d role assignments.',
            count($saccoUsers), count($vehicleUsers), count($assignments)
        ));

        $dryRun = (bool) $this->option('dry-run');
        $counts = ['sacco_users' => 0, 'vehicle_users' => 0, 'roles' => 0, 'skipped_missing_user' => 0, 'skipped_missing_vehicle' => 0];

        // Referential guards. The first slice imported 883 vehicles and 6,796
        // users; anything pointing outside that is a legacy row whose target was
        // never migrated, and inserting it would break the foreign key.
        $userIds = DB::table('users')->pluck('id')->flip();
        $vehicleIds = DB::table('vehicles')->pluck('id')->flip();
        $roleIds = DB::table('roles')->pluck('id', 'name');

        $apply = function () use ($saccoUsers, $vehicleUsers, $assignments, $userIds, $vehicleIds, $roleIds, &$counts) {
            foreach ($saccoUsers as $r) {
                if (! isset($userIds[$r['user_id']])) {
                    $counts['skipped_missing_user']++;

                    continue;
                }
                DB::table('sacco_users')->updateOrInsert(['id' => $r['id']], [
                    'user_id' => $r['user_id'],
                    'sacco_id' => $r['sacco_id'],
                    'start_date' => $r['start_date'] ?? null,
                    'end_date' => $r['end_date'] ?? null,
                    'status' => (bool) ($r['status'] ?? 1),
                    // created_by is a FK to users; null it when the creator was
                    // never migrated rather than dropping the membership row.
                    'created_by' => isset($userIds[$r['created_by'] ?? null]) ? $r['created_by'] : null,
                    'created_at' => $r['created_at'] ?? now(),
                    'updated_at' => now(),
                ]);
                $counts['sacco_users']++;
            }

            foreach ($vehicleUsers as $r) {
                if (! isset($userIds[$r['user_id']])) {
                    $counts['skipped_missing_user']++;

                    continue;
                }
                if (! isset($vehicleIds[$r['vehicle_id']])) {
                    $counts['skipped_missing_vehicle']++;

                    continue;
                }
                DB::table('vehicle_users')->updateOrInsert(['id' => $r['id']], [
                    'user_id' => $r['user_id'],
                    'vehicle_id' => $r['vehicle_id'],
                    'sacco_id' => $r['sacco_id'] ?? null,
                    'start_date' => $r['start_date'] ?? now(),
                    'end_date' => $r['end_date'] ?? null,
                    'status' => (bool) ($r['status'] ?? 1),
                    'created_at' => $r['created_at'] ?? now(),
                    'updated_at' => now(),
                ]);
                $counts['vehicle_users']++;
            }

            foreach ($assignments as $a) {
                $target = self::ROLE_MAP[$a['role_name']] ?? null;
                if ($target === null || ! isset($userIds[$a['model_id']]) || ! isset($roleIds[$target])) {
                    continue;
                }
                DB::table('model_has_roles')->updateOrInsert([
                    'role_id' => $roleIds[$target],
                    'model_type' => \App\Models\User::class,
                    'model_id' => $a['model_id'],
                ], []);
                $counts['roles']++;
            }
        };

        if ($dryRun) {
            DB::beginTransaction();
            try {
                $apply();
            } finally {
                DB::rollBack();
            }
            $this->render($counts);
            $this->info('Dry run — rolled back, nothing written.');

            return self::SUCCESS;
        }

        DB::transaction($apply);

        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['sacco_users', 'vehicle_users'] as $t) {
                DB::statement("SELECT setval(pg_get_serial_sequence('{$t}', 'id'), COALESCE((SELECT MAX(id) FROM {$t}), 1))");
            }
        }

        $this->render($counts);

        $noRole = DB::table('users as u')->where('u.type', '!=', 'passenger')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('model_has_roles as m')->whereColumn('m.model_id', 'u.id'))
            ->count();
        $this->line("non-passenger users still without a role: {$noRole}");

        return self::SUCCESS;
    }

    private function render(array $counts): void
    {
        $this->newLine();
        $this->table(['what', 'rows'], collect($counts)->map(fn ($v, $k) => [$k, $v])->values()->all());
    }
}
