<?php

namespace App\Console\Commands;

use App\Models\Mpesa;
use App\Models\Transaction;
use App\Models\Vehicle;
use App\Models\Summary;
use Carbon\Carbon;
use Illuminate\Console\Command;
use DB;

class CopyMpesa extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'copy:mpesa
        {--confirm-legacy-migration : Required. This replays legacy money into the live money tables}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Copy mpesa transactions from previous main server before disabling it';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Same fail-closed shape as the LEGACY_BASE_URL check below, and a second
        // lock on the same door. Being unscheduled removes the timer; it does not
        // remove the command, and a person typing it is now the ONLY way it fires
        // — which is precisely the case the scheduler comment in
        // app/Console/Kernel.php says must not happen casually.
        //
        // What one accidental run costs: the cursor handed to the remote is the
        // newest TransID in `mpesas`, a table five other producers also write to,
        // so it is usually an id the remote never issued — and an unknown cursor
        // there replays from the beginning of history rather than erroring. Every
        // replayed row then hits the summary block below, which adds to
        // mpesa_amount and mpesa_txn unconditionally, with no "already recorded"
        // check of the kind C2bPaymentRecorder has. The result is not an error
        // anyone sees; it is inflated day totals for real buses.
        //
        // A flag nobody types by accident is the whole guard.
        if (! $this->option('confirm-legacy-migration')) {
            $this->error('copy:mpesa replays legacy money into the live tables. Re-run with --confirm-legacy-migration.');
            $this->line('Its cursor usually makes the remote replay from the start of history, and every');
            $this->line('row it receives is re-added to that vehicle\'s day summary — no double-count guard.');
            $this->line('See app/Console/Kernel.php for why it is unscheduled.');

            return self::FAILURE;
        }

        $this->info('Starting CopyMpesa command...');

        // The legacy host is configuration, not a constant. This was hard-coded
        // to https://test.komiut.co.ke — which is NOT a test environment: it is
        // a DNS alias for komiut.co.ke, a live box still receiving real M-Pesa
        // confirmations. Fail closed so an accidental run cannot pull customer
        // payment records out of production.
        $base = rtrim((string) config('services.legacy.base_url'), '/');
        if ($base === '') {
            $this->error('services.legacy.base_url (LEGACY_BASE_URL) is not set.');
            $this->line('This command imports money rows from the OLD system and is not part of');
            $this->line('normal operation. Set it deliberately, for one migration run only.');

            return self::FAILURE;
        }

        $mpesa = Mpesa::orderBy('id', 'desc')->first();
        $mpesa_id = 0;

        if ($mpesa != null) {
            $mpesa_id = $mpesa->TransID;
            $this->info("Last Mpesa TransID found: {$mpesa_id}");
        } else {
            $this->info("No Mpesa record found, starting from 0.");
        }

        $url = $base . "/api/mpesas/copy?trans_id=" . urlencode($mpesa_id);
        $this->info("Fetching data from URL: {$url}");

        try {
            $data = file_get_contents($url);
            $this->info("Data fetched successfully.");
        } catch (\Exception $e) {
            $this->error("Failed to fetch data: " . $e->getMessage());
            return 1;
        }

        $json = json_decode($data, true);

        if (!isset($json['mpesas'])) {
            $this->error("Invalid JSON response or 'mpesas' key missing.");
            return 1;
        }

        $this->info("Number of mpesas received: " . count($json['mpesas']));

        foreach ($json["mpesas"] as $mpesa) {
            $this->info("Processing Mpesa: " . $mpesa['TransID']);

            DB::transaction(function () use ($mpesa) {
                $this->info("Checking if Mpesa exists in DB...");
                $myMpesa = Mpesa::where('TransID', $mpesa['TransID'])->first();

                if ($myMpesa == null) {
                    $this->info("Creating new Mpesa record.");
                    $myMpesa = new Mpesa();
                } else {
                    $this->info("Updating existing Mpesa record.");
                }

                $myMpesa->TransID = $mpesa['TransID'];
                $myMpesa->MSISDN = $mpesa['MSISDN'];
                $myMpesa->TransAmount = $mpesa['TransAmount'];
                $myMpesa->TransTime = $mpesa['TransTime'];
                $myMpesa->FirstName = $mpesa['FirstName'];
                $myMpesa->LastName = $mpesa['LastName'];
                $myMpesa->MiddleName = $mpesa['MiddleName'];
                $myMpesa->ThirdPartyTransID = $mpesa['ThirdPartyTransID'] ?? "";
                $myMpesa->InvoiceNumber = $mpesa['ThirdPartyTransID'] ?? "";
                $myMpesa->BillRefNumber = $mpesa['BillRefNumber'] ?? "";
                $myMpesa->BusinessShortCode = $mpesa['BusinessShortCode'];
                $myMpesa->TransactionType = $mpesa['TransactionType'];

                if ($myMpesa->save()) {
                    $this->info("Mpesa record saved. ID: {$myMpesa->id}");

                    $transaction = Transaction::where('mpesa_id', $myMpesa->id)->first();
                    if ($transaction == null) {
                        $this->info("Creating new Transaction record.");
                        $transaction = new Transaction();
                    }

                    $vehicle = Vehicle::where('merchant_short_code', $myMpesa->BusinessShortCode)->first();
                    if ($vehicle != null) {
                        $this->info("Vehicle found: {$vehicle->id}");

                        $transaction->vehicle_id = $vehicle->id;
                        $transDate = Carbon::parse($myMpesa->TransTime)->format('Y-m-d');

                        $summary = Summary::where('vehicle_id', $transaction->vehicle_id)
                            ->where('trans_date', $transDate)
                            ->lockForUpdate()
                            ->first();

                        if ($summary == null) {
                            $this->info("Creating new Summary record.");
                            $summary = new Summary;
                            $summary->mpesa_amount = 0;
                            $summary->cash_amount = 0;
                            $summary->mpesa_txn = 0;
                            $summary->cash_txn = 0;
                        }

                        $summary->vehicle_id = $vehicle->id;
                        $summary->mpesa_amount += $myMpesa->TransAmount;
                        $summary->mpesa_txn += 1;
                        $summary->trans_date = $transDate;
                        $summary->save();

                        $this->info("Summary record updated/saved.");
                    } else {
                        $this->warn("No matching vehicle found for shortcode: {$myMpesa->BusinessShortCode}");
                    }

                    $transaction->mpesa_id = $myMpesa->id;
                    $transaction->amount = $myMpesa->TransAmount;
                    $transaction->trans_date = Carbon::parse($myMpesa->TransTime);
                    $transaction->summarized = true;
                    $transaction->save();

                    $this->info("Transaction saved.");
                }
            });
        }

        $this->info("CopyMpesa command completed successfully.");
        return 0;
    }

}
