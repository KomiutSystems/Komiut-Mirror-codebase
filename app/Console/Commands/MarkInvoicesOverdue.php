<?php

namespace App\Console\Commands;

use App\Services\Billing\InvoiceService;
use Illuminate\Console\Command;

/**
 * Flag issued/partial invoices past their due date as overdue. Scheduled daily.
 */
class MarkInvoicesOverdue extends Command
{
    protected $signature = 'invoices:mark-overdue';

    protected $description = 'Flag issued/partial invoices past their due date as overdue';

    public function handle(InvoiceService $service): int
    {
        $count = $service->markOverdue();

        $this->info("Marked {$count} invoice(s) overdue.");

        return self::SUCCESS;
    }
}
