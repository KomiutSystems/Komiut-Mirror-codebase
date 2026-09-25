<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MpesaPaymentSetting;
use App\Services\Mpesa\DarajaClient;
use App\Services\Mpesa\PullTransactionImporter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recover C2B payments Safaricom took but never delivered, by asking for them.
 *
 *   php artisan payments:pull --from="2026-09-25 05:00" --to="2026-09-25 09:30"
 *   php artisan payments:pull --hours=3 --write
 *   php artisan payments:pull --register --shortcode=3702865
 *
 * WHICH TILLS. Every shortcode that has delivered a customer payment in the
 * last 14 days, paired with the Daraja app that registered it. That app is
 * named by the id in the till's ConfirmationURL, /api/confirmation/{id} -- but
 * that id belongs to the LEGACY PAYMENTS SERVER's own app table
 * (komiut_payments.mpesa_settings), which registered every till, NOT to our
 * mpesa_payment_settings. The two numberings differ (URL id 15 is the app for
 * Head Office 5502888, which is our setting 17), so the app is found by its
 * Head Office shortcode through LEGACY_URL_APP, and our credentials for that
 * shortcode are used. Tills whose app we hold no credentials for are listed,
 * not guessed at.
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
    /**
     * ConfirmationURL id -> the Head Office (Go-Live) shortcode of the Daraja
     * app behind it, from komiut_payments.mpesa_settings on the legacy master
     * (read 2026-09-25). Frozen history: that server is retired and registers
     * nothing new. Tills registered by THIS system's registrar carry our own
     * setting ids and are resolved directly.
     */
    public const LEGACY_URL_APP = [
        1 => '5001130', 2 => '5142602', 3 => '5142864', 4 => '5339502', 5 => '5339734', 6 => '5339736',
        7 => '5339832', 8 => '5339836', 9 => '5339838', 10 => '5339840', 11 => '5339842', 12 => '5339834',
        13 => '5342498', 14 => '5495094', 15 => '5502888', 16 => '5512018', 17 => '5557936', 18 => '3534069',
        19 => '3548787', 20 => '3571373', 21 => '3572989', 22 => '3567571', 23 => '3573899', 24 => '3573021',
        25 => '3574003', 26 => '3573017', 27 => '3581897', 28 => '3020809', 29 => '3020895', 30 => '4564233',
        31 => '4064116', 32 => '4887993', 33 => '4125044',
    ];

    protected $signature = 'payments:pull
        {--from= : Window start, "Y-m-d H:i" Nairobi time}
        {--to= : Window end, "Y-m-d H:i" Nairobi time (default now)}
        {--hours=3 : Trailing window when --from is not given}
        {--shortcode=* : Only these till shortcodes}
        {--ho=* : Only tills under these Head Office shortcodes}
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

        $settings = MpesaPaymentSetting::withoutGlobalScopes()->get()->sortBy('is_live')->keyBy('id');
        $targets = $this->resolve($this->targets(), $settings);
        if ($only = array_filter((array) $this->option('ho'))) {
            $targets = array_values(array_filter($targets, fn ($t) => in_array($t['ho'], $only, true)));
        }
        $noCreds = array_filter($targets, fn ($t) => $t['setting'] === null);
        $targets = array_values(array_filter($targets, fn ($t) => $t['setting'] !== null));

        $this->info(sprintf('Window %s .. %s (Nairobi). %d till(s) to pull%s.', $from->format('Y-m-d H:i'), $to->format('Y-m-d H:i'),
            count($targets), $noCreds ? ', '.count($noCreds).' skipped (no credentials for their app)' : ''));
        if ($this->option('list')) {
            $this->table(['Till', 'Head Office', 'Our setting', 'Payments, 14 days'], array_map(fn ($t) => [$t['short_code'], $t['ho'], $t['setting'], $t['n']], $targets));
            if ($noCreds) {
                $this->warn('No credentials held for: '.implode(', ', array_map(fn ($t) => "{$t['short_code']} (HO ".($t['ho'] ?? "unknown, URL id {$t['url_id']}").')', $noCreds)));
            }

            return self::SUCCESS;
        }

        $nominated = self::msisdn((string) ($this->option('nominated') ?: config('services.mpesa_pull.nominated_number')));
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

            // Recorded rows carry the ConfirmationURL id, as a delivered confirmation does.
            $r = $importer->run($client, $t['short_code'], (int) $t['url_id'], $from, $to, (bool) $this->option('write'), (bool) $this->option('utc'));
            if (! $r['ok']) {
                $refused++;
            }
            foreach (['pulled', 'held', 'missing', 'recorded', 'failed'] as $k) {
                $totals[$k] += $r[$k];
            }
            $totals['kes'] += $r['missing_kes'];
            $report[] = [$t['short_code'], $t['ho'], $r['pulled'], $r['held'], $r['missing'], number_format($r['missing_kes'], 0),
                $r['drift'] === null ? '-' : $r['drift'].'s', $r['recorded'], $r['ok'] ? ($r['message'] ?: 'ok') : 'REFUSED: '.$r['message']];
        }

        $this->table(['Till', 'Head Office', 'Safaricom has', 'We hold', 'Missing', 'Missing KES', 'Clock', 'Recorded', 'Note'], $report);
        $this->info(sprintf('Total: Safaricom has %d, we hold %d, missing %d (KES %s), recorded %d, failed %d.%s',
            $totals['pulled'], $totals['held'], $totals['missing'], number_format($totals['kes'], 2), $totals['recorded'], $totals['failed'],
            $this->option('write') ? '' : ' DRY RUN -- re-run with --write to record.'));

        return ($refused || $totals['failed']) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Each till's Daraja app and our credentials for it. A till moved by this
     * system's registrar (TillRegistrationController) was registered with OUR
     * setting id, recorded on the vehicle; every other till still carries the
     * legacy payments server's id, read through LEGACY_URL_APP. A till seen
     * under both is one target.
     *
     * @param  list<array{short_code:string, url_id:int, n:int}>  $targets
     * @return list<array{short_code:string, url_id:int, n:int, ho:?string, setting:?int}>
     */
    private function resolve(array $targets, Collection $settings): array
    {
        $byHo = $settings->keyBy(fn ($s) => (string) $s->business_short_code);   // sorted by is_live: a live app wins
        $moved = DB::table('vehicles')->whereNotNull('till_registered_url')->whereNotNull('merchant_short_code')
            ->pluck('till_registered_url', 'merchant_short_code');

        $out = [];
        foreach ($targets as $t) {
            if (isset($moved[$t['short_code']]) && preg_match('#/api/confirmation/(\d+)$#', (string) $moved[$t['short_code']], $m) && $settings->has((int) $m[1])) {
                $t['url_id'] = (int) $m[1];
                $t['setting'] = (int) $m[1];
                $t['ho'] = (string) $settings[(int) $m[1]]->business_short_code;
            } else {
                $t['ho'] = self::LEGACY_URL_APP[$t['url_id']] ?? null;
                $t['setting'] = $t['ho'] !== null && $byHo->has($t['ho']) ? (int) $byHo[$t['ho']]->id : null;
            }
            if (isset($out[$t['short_code']])) {
                $out[$t['short_code']]['n'] += $t['n'];

                continue;
            }
            $out[$t['short_code']] = $t;
        }

        return array_values($out);
    }

    /** Safaricom refuses "0114887501" as a NominatedNumber and accepts "254114887501". */
    public static function msisdn(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';

        return preg_match('/^0([17]\d{8})$/', $digits, $m) ? '254'.$m[1] : $digits;
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

    /** @return list<array{short_code:string, url_id:int, n:int}> */
    private function targets(): array
    {
        $q = DB::table('mpesas')
            ->selectRaw('"BusinessShortCode" as short_code, mpesa_setting_id as url_id, count(*) as n')
            ->where('TransTime', '>=', now()->subDays(14))
            ->whereNotNull('mpesa_setting_id')
            ->whereRaw("coalesce(\"TransactionType\", '') not like 'Organization%'")
            ->whereRaw("\"BusinessShortCode\" ~ '^[0-9]+$'")
            ->groupBy('BusinessShortCode', 'mpesa_setting_id')
            ->orderByDesc('n');
        if ($only = array_filter((array) $this->option('shortcode'))) {
            $q->whereIn('BusinessShortCode', $only);
        }

        return array_map(fn ($r) => ['short_code' => (string) $r->short_code, 'url_id' => (int) $r->url_id, 'n' => (int) $r->n], $q->get()->all());
    }
}
