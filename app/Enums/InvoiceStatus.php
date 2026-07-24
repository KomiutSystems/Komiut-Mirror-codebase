<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a SACCO billing invoice. Transitions are driven by InvoiceService
 * (issue → record payments → recompute), never dictated by an M-Pesa payload.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';     // created, not yet issued to the SACCO
    case Issued = 'issued';   // billed, awaiting payment
    case Partial = 'partial'; // part-paid, balance remaining
    case Paid = 'paid';       // settled in full
    case Overdue = 'overdue'; // past due_date, still owing
    case Void = 'void';       // cancelled, never collectible
}
