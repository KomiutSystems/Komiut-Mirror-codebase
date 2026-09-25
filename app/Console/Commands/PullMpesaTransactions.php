<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MpesaPaymentSetting;
use App\Services\Mpesa\DarajaClient;
use App\Services\Mpesa\PullTransactionImporter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recover C2B payments Safaricom took but never delivered, by asking for them.
 *
 *   php artisan payments:pull --from="2026-09-25 05:00" --to="2026-09-25 09:30"
 *   php artisan payments:pull --hours=3 --write
 *   php artisan payments:pull --register --shortcode=3702865
 *
 * WHICH TILLS. Every shortcode that has delivered a customer payment in the
 * last 14 days, paired with the Daraja app that delivered it -- the setting id
 * in its confirmation URL, /api/confirmation/{id}. That app's credentials are
 * the ones Safaricom accepts for that till, so they are the ones used to pull
 * it. Tills whose app we hold no credentials for are listed, not guessed at.
 *
 * REGISTRATION. Safaricom answers every unregistered shortcode with "No records
 * found" (code 1001) -- the same words as a quiet till -- so Pull must be
 * registered once before a query means anything. Per Safaricom's documentation
 * the register call takes "the Organization ShortCode that was used during the
 * Go-Live process" -- the Daraja app's own shortcode, not each till -- plus a
 * NominatedNumber, "the Safaricom MSISDN associated with the organization
 * account". --register does that once per app; --register-tills also
 * registers every till shortcode, for apps where the org registration turns
 * out not to cover its tills. Response 1000 = registered, 1001 = already.
 *
 * DRY RUN unless --write. A write is refused per till when the clock check in
 * PullTransactionImporter fails, so a timezone mistake cannot land money on the
 * wrong business day.
 */
class PullMpesaTransactions extends Command
{
    protected $signature = 'payments:pull
        {--from= : Window start, "Y-m-d H:i" Nairobi time}
        {--to= : Window end, "Y-m-d H:i" Nairobi time (default now)}
        {--hours=3 : Trailing window when --from is not given}
        {--shortcode=* : Only these till shortcodes}
        {--setting=* : Only tills delivered by these Daraja app ids}
        {--write : Record the payments we do not hold (default: dry run)}
        {--utc : Read Safaricom\'s trxDate as UTC (only if the clock check says so)}
        {--register : Register each targeted Daraja app Go-Live shortcode for Pull before querying}
        {--register-tills : Also register every targeted till shortcode}
        {--nominated= : The org\'s nominated Safaricom number, for --register}
        {--list : Only list the tills this would pull, and their apps}';

    protected $description = 'Pull C2B payments from Safaricom and record any that were never delivered';

    public function handle(PullTransactionImporter $importer): int
    {
        $to = $this->option('to') ? Carbon::parse($this->option('to'), 'UTC') : now('Africa/Nairobi')->shiftTimezone('UTC');
        $from = $this->option('from') ? Carbon::parse($this->option('from'), 'UTC') : $to->copy()->subHours((int) $this->option('hours'));
        if ($from->gte($to)) {
            $this->error('--from must be before --to.');

            return self::INVALID;
        }

        $targets = $this->targets();
        $settings = MpesaPaymentSetting::withoutGlobalScopes()->whereIn('id', array_unique(array_column($targets, 'setting')))->get()->keyBy('id');
        $noCreds = array_filter($targets, fn ($t) => ! $settings->has($t['setting']));
        $targets = array_values(array_filter($targets, fn ($t) => $settings->has($t['setting'])));

        $this->info(sprintf('Window %s .. %s (Nairobi). %d till(s) to pull%s.', $from->format('Y-m-d H:i'), $to->format('Y-m-d H:i'),
            count($targets), $noCreds ? ', '.count($noCreds).' skipped (no credentials for their app)' : ''));
        if ($this->option('list')) {
            $this->table(['Shortcode', 'App (setting id)', 'Payments, 14 days'], array_map(fn ($t) => [$t['short_code'], $t['setting'], $t['n']], $targets));
            if ($noCreds) {
                $this->warn('No credentials held for: '.implode(', ', array_map(fn ($t) => "{$t['short_code']} (app {$t['setting']})", $noCreds)));
            }

            return self::SUCCESS;
        }

        $nominated = (string) ($this->option('nominated') ?: config('services.mpesa_pull.nominated_number'));
        if ($this->option('register') && $nominated === '') {
            $this->error('--register needs --nominated=<the org\'s nominated Safaricom number> (or MPESA_PULL_NOMINATED_NUMBER).');

            return self::INVALID;
        }

        $totals = ['pulled' => 0, 'held' => 0, 'missing' => 0, 'recorded' => 0, 'failed' => 0, 'kes' => 0.0];
        $report = [];
        $refused = 0;
        $client = fn (MpesaPaymentSetting $s) => new DarajaClient((string) $s->consumer_key, (string) $s->consumer_secret, (string) $s->business_short_code, (string) $s->pass_key, (bool) $s->is_live);
        $callback = fn (int $settingId) => rtrim((string) config('services.mpesa_pull.callback_url'), '/').'/'.$settingId;

        if ($this->option('register') || $this->option('register-tills')) {
            $shortCodes = [];
            foreach (array_unique(array_column($targets, 'setting')) as $settingId) {
                $shortCodes[] = [$settingId, (string) $settings[$settingId]->business_short_code, 'app Go-Live shortcode'];
            }
            if ($this->option('register-tills')) {
                foreach ($targets as $t) {
                    $shortCodes[] = [$t['setting'], $t['short_code'], 'till'];
                }
            }
            foreach ($shortCodes as [$settingId, $shortCode, $what]) {
                $reg = $client($settings[$settingId])->registerPull($shortCode, $nominated, $callback($settingId));
                $this->line(sprintf('  register %s (%s, app %d): %s', $shortCode, $what, $settingId, self::describe($reg)));
            }
        }

        foreach ($targets as $t) {
            /** @var MpesaPaymentSetting $s */
            $s = $settings[$t['setting']];
            $client = new DarajaClient((string) $s->consumer_key, (string) $s->consumer_secret, (string) $s->business_short_code, (string) $s->pass_key, (bool) $s->is_live);

            $r = $importer->run($client, $t['short_code'], (int) $t['setting'], $from, $to, (bool) $this->option('write'), (bool) $this->option('utc'));
            if (! $r['ok']) {
                $refused++;
            }
            foreach (['pulled', 'held', 'missing', 'recorded', 'failed'] as $k) {
                $totals[$k] += $r[$k];
            }
            $totals['kes'] += $r['missing_kes'];
            $report[] = [$t['short_code'], $t['setting'], $r['pulled'], $r['held'], $r['missing'], number_format($r['missing_kes'], 0),
                $r['drift'] === null ? '-' : $r['drift'].'s', $r['recorded'], $r['ok'] ? ($r['message'] ?: 'ok') : 'REFUSED: '.$r['message']];
        }

        $this->table(['Till', 'App', 'Safaricom has', 'We hold', 'Missing', 'Missing KES', 'Clock', 'Recorded', 'Note'], $report);
        $this->info(sprintf('Total: Safaricom has %d, we hold %d, missing %d (KES %s), recorded %d, failed %d.%s',
            $totals['pulled'], $totals['held'], $totals['missing'], number_format($totals['kes'], 2), $totals['recorded'], $totals['failed'],
            $this->option('write') ? '' : ' DRY RUN -- re-run with --write to record.'));

        return ($refused || $totals['failed']) ? self::FAILURE : self::SUCCESS;
    }

    /** Safaricom's register answer, whose keys contain spaces ("Response Description"). */
    private static function describe(?array $reg): string
    {
        if ($reg === null) {
            return 'no answer';
        }
        $status = $reg['Response Status'] ?? $reg['ResponseStatus'] ?? $reg['ResponseCode'] ?? $reg['errorCode'] ?? '?';
        $text = $reg['Response Description'] ?? $reg['ResponseDescription'] ?? $reg['ResponseMessage'] ?? $reg['errorMessage'] ?? json_encode($reg);

        return trim((string) $text).' ('.$status.')';
    }

    /** @return list<array{short_code:string, setting:int, n:int}> */
    private function targets(): array
    {
        $q = DB::table('mpesas')
            ->selectRaw('"BusinessShortCode" as short_code, mpesa_setting_id as setting, count(*) as n')
            ->where('TransTime', '>=', now()->subDays(14))
            ->whereNotNull('mpesa_setting_id')
            ->whereRaw("coalesce(\"TransactionType\", '') not like 'Organization%'")
            ->whereRaw("\"BusinessShortCode\" ~ '^[0-9]+$'")
            ->groupBy('BusinessShortCode', 'mpesa_setting_id')
            ->orderByDesc('n');
        if ($only = array_filter((array) $this->option('shortcode'))) {
            $q->whereIn('BusinessShortCode', $only);
        }
        if ($apps = array_filter((array) $this->option('setting'))) {
            $q->whereIn('mpesa_setting_id', array_map('intval', $apps));
        }

        return array_map(fn ($r) => ['short_code' => (string) $r->short_code, 'setting' => (int) $r->setting, 'n' => (int) $r->n], $q->get()->all());
    }
}
