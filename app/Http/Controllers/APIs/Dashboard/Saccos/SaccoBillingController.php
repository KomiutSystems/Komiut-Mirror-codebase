<?php

namespace App\Http\Controllers\APIs\Dashboard\Saccos;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Http\Request;

/**
 * @group SACCO billing
 *
 * A SACCO's own subscription and invoices. Every read is SACCO-scoped
 * automatically (a SACCO can never see another SACCO's billing); superadmins
 * see across SACCOs and may target one with `sacco_id`. Invoices are settled by
 * paying the `invoice_number` as the M-Pesa account reference to the billing
 * paybill — management (rates, generation) lives in the superadmin billing API.
 */
class SaccoBillingController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Show current subscription
     *
     * The SACCO's plan and next billing date.
     *
     * @authenticated
     *
     * @queryParam sacco_id integer Superadmin only — inspect another SACCO. Example: 1
     */
    public function subscription(Request $request)
    {
        $query = Subscription::with('plan');
        if ($request->filled('sacco_id')) {
            $query->where('sacco_id', (int) $request->sacco_id);
        }

        return response()->json(['subscription' => $query->latest('id')->first()]);
    }

    /**
     * List invoices
     *
     * @authenticated
     *
     * @queryParam status string Filter by status (issued, partial, paid, overdue, void). Example: issued
     * @queryParam sacco_id integer Superadmin only — another SACCO's invoices. Example: 1
     * @queryParam page integer Page number (20 per page). Example: 1
     */
    public function invoices(Request $request)
    {
        $offset = max(0, ((int) $request->page) - 1) * 20;

        $query = Invoice::with('items')->orderByDesc('id');
        if ($request->filled('sacco_id')) {
            $query->where('sacco_id', (int) $request->sacco_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json(['invoices' => $query->skip($offset)->take(20)->get()]);
    }

    /**
     * Show one invoice
     *
     * Includes line items, payments and the M-Pesa payment instructions. Route-
     * model binding is SACCO-scoped, so fetching another SACCO's invoice 404s.
     *
     * @authenticated
     *
     * @urlParam invoice integer required The invoice id. Example: 1
     */
    public function showInvoice(Invoice $invoice)
    {
        $invoice->load('items', 'payments', 'sacco');

        return response()->json([
            'invoice' => $invoice,
            'payment_instructions' => [
                'method' => 'M-Pesa',
                'account_reference' => $invoice->invoice_number,
                'amount_due' => $invoice->balance,
                'currency' => $invoice->currency,
            ],
        ]);
    }
}
