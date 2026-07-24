<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Services\Billing\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @group Billing M-Pesa callbacks
 *
 * Unauthenticated C2B endpoints Safaricom posts to when a SACCO pays a billing
 * invoice. The invoice is matched by `BillRefNumber` = `invoice_number`.
 *
 * C2B payloads carry no signature, so the defence is layered in InvoiceService,
 * not here: receipt dedupe (unique `mpesa_receipt`), an amount clamp (balance
 * never goes negative), and the payload never sets status directly — we record
 * the payment and recompute from the sum of payments. This mirrors the STK
 * hardening that closed the forged-callback free-ride hole.
 */
class BillingMpesaController extends Controller
{
    /**
     * C2B validation
     *
     * Accepts every reference (invoices are not pre-registered with Safaricom).
     *
     * @unauthenticated
     */
    public function validation()
    {
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    /**
     * C2B confirmation
     *
     * Records a SACCO invoice payment. Always ACKs (ResultCode 0) so Safaricom
     * does not retry; unmatched references are logged for manual reconciliation.
     *
     * @unauthenticated
     *
     * @bodyParam BillRefNumber string required The invoice number paid. Example: INV-1-202608
     * @bodyParam TransAmount number required Amount paid in KES. Example: 800
     * @bodyParam TransID string required M-Pesa receipt (deduped). Example: QGH12ABCD
     * @bodyParam MSISDN string Payer phone number. Example: 254700111222
     */
    public function confirmation(Request $request, InvoiceService $service)
    {
        $reference = $request->input('BillRefNumber', $request->input('bill_ref_number'));
        $amount = (float) $request->input('TransAmount', $request->input('amount', 0));
        $receipt = $request->input('TransID', $request->input('receipt'));
        $phone = $request->input('MSISDN', $request->input('phone'));

        $invoice = $service->matchByReference($reference);
        if (! $invoice) {
            Log::warning('billing C2B: no invoice for reference', [
                'reference' => $reference,
                'receipt' => $receipt,
                'amount' => $amount,
            ]);

            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Received']);
        }

        $service->recordPayment($invoice, $amount, [
            'method' => 'mpesa',
            'mpesa_receipt' => $receipt,
            'phone' => $phone,
            'raw' => $request->all(),
        ]);

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Confirmed']);
    }
}
