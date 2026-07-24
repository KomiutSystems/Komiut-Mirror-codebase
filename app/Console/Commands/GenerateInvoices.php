<?php

namespace App\Console\Commands;

use App\Services\Billing\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Raise due SACCO subscription invoices. Scheduled daily; also runnable on
 * demand (and from the superadmin "generate now" endpoint).
 */
class GenerateInvoices extends Command
{
    protected $signature = 'invoices:generate {--date= : Generate as of this date (Y-m-d); defaults to today}';

    protected $description = 'Generate due SACCO subscription invoices';

    public function handle(InvoiceService $service): int
    {
        $asOf = $this->option('date') ? Carbon::parse($this->option('date')) : null;
        $created = $service->generateDueInvoices($asOf);

        $this->info('Generated ' . count($created) . ' invoice(s).');

        return self::SUCCESS;
    }
}
