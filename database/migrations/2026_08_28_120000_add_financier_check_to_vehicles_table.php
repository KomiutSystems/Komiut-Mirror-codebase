<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the last free-text hole in the financier boundary.
 *
 * `users.financier` has carried a CHECK constraint since it was created
 * (2026_08_24_100000). `vehicles.financier` never got one: it was added as a
 * plain nullable varchar because the values arrive from a legacy free-text
 * column. That asymmetry is the bug. The user side is only ever written
 * deliberately by a superadmin, while the vehicle side is written verbatim by
 * ImportLegacyVehicles — the side with an automated writer is the side with no
 * validation.
 *
 * What an unconstrained value costs: FinancierScope resolves through
 * Financier::tryParse, which is tryFrom, so an unrecognised string becomes
 * null. On a USER that is the fail-closed branch and the account is denied
 * loudly-ish (an empty dashboard). On a VEHICLE there is no branch at all —
 * the row simply never equals 'NCBA' and never equals 'coop-bank', so it drops
 * out of both banks' views and out of SendBankCollectionsStatement's totals.
 * A single lowercase 'ncba' is a bus whose money is invisible to the bank that
 * financed it, to the other bank, and to the statement that reconciles them,
 * with nothing anywhere raising an error. 829 vehicles carry 'NCBA' and 54
 * carry 'coop-bank'; there is no third legitimate spelling.
 *
 * Two deliberate choices below.
 *
 * NORMALISE FIRST, and only what is provably the same value. Case and
 * surrounding whitespace are the realistic damage from a hand-edited legacy
 * column, and 'ncba' can have meant nothing except 'NCBA'. Anything else —
 * 'Coop Bank', 'KCB', a half-typed string — is NOT guessed at: guessing would
 * assign a bus to a bank that never financed it, which is worse than the
 * invisibility this migration exists to fix. Those rows stay as they are and
 * are reported.
 *
 * NOT VALID when violators survive. PostgreSQL enforces a NOT VALID check on
 * every INSERT and UPDATE — it only skips the retroactive full-table scan — so
 * the importer is stopped either way. Adding it validated on a table that
 * already holds a stray value would abort the migration and take an unrelated
 * deploy down with it, on a platform that is moving real money. The constraint
 * goes on regardless; only the backfill verdict differs, and the surviving rows
 * are printed so a human can decide what they were meant to say.
 *
 * The allowed values are written out rather than imported from App\Enums\
 * Financier, matching the users-table migration: a migration is a historical
 * record and must not change meaning if the enum later gains a case.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'vehicles_financier_check';

    /** The canonical spellings, and the only ones this column may hold. */
    private const ALLOWED = ['NCBA', 'coop-bank'];

    public function up(): void
    {
        // The column arrived in 2026_08_09_110000; if that has not run there is
        // nothing to constrain yet and the guard keeps this migration ordering-safe.
        if (! Schema::hasColumn('vehicles', 'financier')) {
            return;
        }

        // SQLite (the test database) has no ALTER TABLE ... ADD CONSTRAINT, and
        // rebuilding the table to fake one would diverge the test schema from
        // production. Application-side validation is what the tests exercise;
        // this constraint is the backstop under the importer, which is a
        // PostgreSQL-only concern. Same guard as the users-table migration.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->normaliseKnownSpellings();

        if ($this->constraintExists()) {
            return;
        }

        $violating = $this->violatingRows();

        DB::statement(
            'ALTER TABLE vehicles ADD CONSTRAINT ' . self::CONSTRAINT .
            " CHECK (financier IS NULL OR financier IN ('NCBA', 'coop-bank'))" .
            // Enforced on writes either way; NOT VALID only skips the backfill scan.
            ($violating === [] ? '' : ' NOT VALID')
        );

        foreach ($violating as $row) {
            // Not an exception: these rows are already invisible to both banks,
            // and have been since the import. Failing the deploy would not make
            // them visible, it would only stop the constraint that prevents the
            // next one.
            echo sprintf(
                "  vehicles.financier: %d row(s) hold the unrecognised value [%s] (e.g. %s). "
                . "Constraint added NOT VALID; these are invisible to both banks until corrected.\n",
                $row->row_count,
                (string) $row->financier,
                (string) $row->sample_plate,
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql' && $this->constraintExists()) {
            DB::statement('ALTER TABLE vehicles DROP CONSTRAINT ' . self::CONSTRAINT);
        }

        // The normalisation is deliberately NOT reversed. It corrected values to
        // what they already meant; restoring 'ncba' would re-hide a bus from the
        // bank that financed it, and nothing recorded which rows were touched.
    }

    /**
     * Fold the provably-equivalent spellings onto the canonical ones.
     *
     * Blank first: '' and '   ' are "not set" spelled wrong, and NULL is how
     * the rest of the codebase spells that (Financier::tryParse already treats
     * blank as null, so this only aligns the storage with the reading).
     */
    private function normaliseKnownSpellings(): void
    {
        DB::statement("UPDATE vehicles SET financier = NULL WHERE financier IS NOT NULL AND btrim(financier) = ''");

        foreach (self::ALLOWED as $canonical) {
            DB::update(
                'UPDATE vehicles SET financier = ? WHERE financier IS NOT NULL '
                . 'AND financier <> ? AND lower(btrim(financier)) = lower(?)',
                [$canonical, $canonical, $canonical]
            );
        }
    }

    /**
     * What is left that the constraint would reject, grouped so the operator
     * sees each distinct bad spelling once rather than one line per bus.
     *
     * @return array<int, object>
     */
    private function violatingRows(): array
    {
        return DB::select(
            'SELECT financier, COUNT(*) AS row_count, MIN(plate) AS sample_plate '
            . 'FROM vehicles WHERE financier IS NOT NULL AND financier NOT IN (?, ?) '
            . 'GROUP BY financier ORDER BY COUNT(*) DESC',
            self::ALLOWED
        );
    }

    /** PostgreSQL has no ADD CONSTRAINT IF NOT EXISTS, so ask first. */
    private function constraintExists(): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM pg_constraint WHERE conname = ?', [self::CONSTRAINT]
        ) !== null;
    }
};
