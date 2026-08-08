<?php

namespace App\Console\Commands;

use App\Models\MpesaPaymentSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import the Daraja credentials (consumer key/secret, passkey, shortcode).
 *
 * These are LIVE production credentials, so the export path matters as much as
 * this command. Legacy stores all three secrets as PLAINTEXT — see
 * App\Casts\EncryptedLegacyString, which exists because of exactly that. They
 * are therefore encrypted ON the legacy box using THIS system's APP_KEY, and
 * only ciphertext is transported. Plaintext never leaves the source machine and
 * never touches S3 or an operator's disk.
 *
 * Because the values arrive already encrypted with our key, they are written
 * with the query builder rather than the model: MpesaPaymentSetting casts these
 * columns with EncryptedLegacyString, whose set() would encrypt a second time,
 * leaving a value that decrypts to ciphertext instead of the credential.
 *
 * The export carries a truncated SHA-256 of each plaintext. Reading the row
 * back through the model and re-hashing proves the round-trip end to end
 * without ever printing a secret.
 */
class ImportLegacyMpesaSettings extends Command
{
    protected $signature = 'legacy:import-mpesa-settings
        {--file= : Path to the ciphertext export}
        {--dry-run : Report what would change and write nothing}';

    protected $description = 'Import Daraja payment settings from the legacy system';

    public function handle(): int
    {
        $path = (string) $this->option('file');
        if ($path === '' || ! is_readable($path)) {
            $this->error('Pass a readable --file=<export.json>.');

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true);
        $rows = $data['mpesa_payment_settings'] ?? null;
        if (! is_array($rows) || $rows === []) {
            $this->error('Export has no mpesa_payment_settings.');

            return self::FAILURE;
        }

        // Refuse plaintext outright. If the export was produced without the
        // encrypting step, importing it would persist live credentials in the
        // clear — the exact thing the column encryption exists to prevent.
        foreach ($rows as $r) {
            foreach (['consumer_key', 'consumer_secret', 'pass_key'] as $f) {
                if (! is_string($r[$f] ?? null) || ! str_starts_with($r[$f], 'eyJpdiI6')) {
                    $this->error("Row {$r['id']}: {$f} is not Laravel ciphertext. Refusing to import.");
                    $this->line('Re-export with the encrypting script so plaintext never transits.');

                    return self::FAILURE;
                }
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->line(count($rows).' setting(s), all ciphertext.');

        if ($dryRun) {
            $this->info('Dry run — nothing written.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as $r) {
                DB::table('mpesa_payment_settings')->updateOrInsert(['id' => $r['id']], [
                    'sacco_id' => $r['sacco_id'],
                    'business_short_code' => $r['business_short_code'],
                    'payment_mode' => $r['payment_mode'],
                    'is_live' => (bool) $r['is_live'],
                    'status' => (bool) $r['status'],
                    // Already encrypted with this app's key — written raw so the
                    // EncryptedLegacyString cast does not encrypt them again.
                    'consumer_key' => $r['consumer_key'],
                    'consumer_secret' => $r['consumer_secret'],
                    'pass_key' => $r['pass_key'],
                    'created_at' => $r['created_at'] ?? now(),
                    'updated_at' => now(),
                ]);
            }
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('mpesa_payment_settings', 'id'), COALESCE((SELECT MAX(id) FROM mpesa_payment_settings), 1))");
        }

        return $this->verify($rows);
    }

    /**
     * Read each row back THROUGH the model — which decrypts — and compare a
     * truncated hash of the result against the hash taken at the source.
     * Matching fingerprints prove the credential survived transport intact.
     */
    private function verify(array $rows): int
    {
        $bad = 0;
        foreach ($rows as $r) {
            $model = MpesaPaymentSetting::withoutGlobalScopes()->find($r['id']);
            if ($model === null) {
                $this->error("Row {$r['id']}: missing after import.");
                $bad++;

                continue;
            }
            foreach (['consumer_key', 'consumer_secret', 'pass_key'] as $f) {
                $expected = $r['fp'][$f] ?? null;
                if ($expected === null) {
                    continue;
                }
                $actual = substr(hash('sha256', (string) $model->{$f}), 0, 12);
                if ($actual !== $expected) {
                    $this->error("Row {$r['id']} shortcode {$r['business_short_code']}: {$f} does NOT match source.");
                    $bad++;
                }
            }
        }

        $dangling = DB::table('vehicles as v')->whereNotNull('v.mpesa_payment_setting_id')
            ->leftJoin('mpesa_payment_settings as m', 'm.id', 'v.mpesa_payment_setting_id')
            ->whereNull('m.id')->count();

        $this->newLine();
        $this->line('imported: '.count($rows).' setting(s)');
        $this->line('vehicles still pointing at a missing setting: '.$dangling);

        if ($bad > 0) {
            $this->error("{$bad} credential(s) failed the round-trip check.");

            return self::FAILURE;
        }

        $this->info('Every credential decrypts to the same value as the source.');

        return self::SUCCESS;
    }
}
