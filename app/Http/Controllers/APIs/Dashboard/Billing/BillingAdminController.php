<?php

namespace App\Http\Controllers\APIs\Dashboard\Billing;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionType;
use App\Services\Billing\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * @group Billing administration
 *
 * Superadmin-only management of billing plans, SACCO subscriptions and invoice
 * generation. Rates and assignments are controlled here; SACCOs only read their
 * own billing (see the "SACCO billing" group).
 */
class BillingAdminController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware(function (Request $request, \Closure $next) {
            $user = auth()->user();
            if (! $user || ! $user->isSuperAdmin()) {
                abort(403, 'Superadmin only.');
            }

            return $next($request);
        });
    }

    /**
     * List billing plans
     *
     * @authenticated
     */
    public function plans()
    {
        return response()->json(['plans' => SubscriptionType::orderBy('name')->get()]);
    }

    /**
     * Create or update a billing plan
     *
     * Pass `id` to update an existing plan, omit it to create one. Invoice for a
     * period = base_fee + per_vehicle_fee × active vehicles (capped at vehicle_cap).
     *
     * @authenticated
     *
     * @bodyParam id integer Plan id to update; omit to create. Example: 1
     * @bodyParam name string required Plan name. Example: Standard
     * @bodyParam description string Short description. Example: Up to 50 vehicles
     * @bodyParam billing_cycle string required monthly, quarterly or annually. Example: monthly
     * @bodyParam base_fee number Flat fee per period in KES. Example: 500
     * @bodyParam per_vehicle_fee number Fee per active vehicle in KES. Example: 100
     * @bodyParam vehicle_cap integer Stop charging past this many vehicles. Example: 50
     * @bodyParam currency string 3-letter currency code. Example: KES
     * @bodyParam is_active boolean Whether the plan can be assigned. Example: true
     */
    public function savePlan(Request $request)
    {
        $data = Validator::make($request->all(), [
            'id' => 'nullable|integer|exists:subscription_types,id',
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:255',
            'billing_cycle' => 'required|in:monthly,quarterly,annually',
            'base_fee' => 'nullable|numeric|min:0',
            'per_vehicle_fee' => 'nullable|numeric|min:0',
            'vehicle_cap' => 'nullable|integer|min:1',
            'currency' => 'nullable|string|size:3',
            'is_active' => 'nullable|boolean',
        ])->validate();

        $id = $data['id'] ?? null;
        unset($data['id']);
        $data['updated_by'] = auth()->id();

        $plan = SubscriptionType::updateOrCreate(['id' => $id], $data);

        return response()->json(['plan' => $plan], $plan->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Assign a SACCO to a plan
     *
     * Creates or updates the SACCO's subscription and sets the first billing
     * date (defaults to today). One subscription per SACCO.
     *
     * @authenticated
     *
     * @bodyParam sacco_id integer required The SACCO. Example: 1
     * @bodyParam subscription_type_id integer required The plan. Example: 1
     * @bodyParam starts_on date First billing date (Y-m-d); defaults to today. Example: 2026-08-01
     * @bodyParam is_active boolean Whether billing is active. Example: true
     */
    public function assign(Request $request)
    {
        $data = Validator::make($request->all(), [
            'sacco_id' => 'required|integer|exists:saccos,id',
            'subscription_type_id' => 'required|integer|exists:subscription_types,id',
            'starts_on' => 'nullable|date',
            'is_active' => 'nullable|boolean',
        ])->validate();

        $startsOn = isset($data['starts_on']) ? Carbon::parse($data['starts_on'])->startOfDay() : now()->startOfDay();

        $subscription = Subscription::updateOrCreate(
            ['sacco_id' => $data['sacco_id']],
            [
                'subscription_type_id' => $data['subscription_type_id'],
                'starts_on' => $startsOn,
                'next_invoice_date' => $startsOn, // set on assignment, else the "due" query skips the first invoice
                'is_active' => $data['is_active'] ?? true,
                'updated_by' => auth()->id(),
            ]
        );

        return response()->json(['subscription' => $subscription->load('plan')]);
    }

    /**
     * Generate due invoices now
     *
     * Runs the same logic as the daily scheduler, on demand.
     *
     * @authenticated
     *
     * @bodyParam date date As-of date (Y-m-d); defaults to today. Example: 2026-08-01
     */
    public function generate(Request $request, InvoiceService $service)
    {
        $asOf = $request->filled('date') ? Carbon::parse($request->input('date')) : null;
        $created = $service->generateDueInvoices($asOf);

        return response()->json(['generated' => count($created), 'invoices' => $created]);
    }

    /**
     * List all invoices
     *
     * Across every SACCO. SACCO-facing listing is in the SACCO billing group.
     *
     * @authenticated
     *
     * @queryParam sacco_id integer Filter to one SACCO. Example: 1
     * @queryParam status string Filter by status. Example: overdue
     * @queryParam page integer Page number (20 per page). Example: 1
     */
    public function invoices(Request $request)
    {
        $offset = max(0, ((int) $request->page) - 1) * 20;

        $query = Invoice::with(['items', 'sacco'])->orderByDesc('id');
        if ($request->filled('sacco_id')) {
            $query->where('sacco_id', (int) $request->sacco_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json(['invoices' => $query->skip($offset)->take(20)->get()]);
    }

    /**
     * Void an invoice
     *
     * Cancels an invoice so it is no longer collectible. Idempotent.
     *
     * @authenticated
     *
     * @urlParam invoice integer required The invoice id. Example: 1
     */
    public function void(Invoice $invoice)
    {
        $invoice->update(['status' => InvoiceStatus::Void, 'balance' => 0]);

        return response()->json(['invoice' => $invoice]);
    }

    /**
     * Record a manual payment
     *
     * For payments received outside the M-Pesa callback (bank transfer, cash, a
     * reconciliation correction). Recomputes the invoice balance and status.
     *
     * @authenticated
     *
     * @urlParam invoice integer required The invoice id. Example: 1
     * @bodyParam amount number required Amount received in KES. Example: 800
     * @bodyParam reference string M-Pesa/bank reference (deduped). Example: QGH12ABCD
     */
    public function recordPayment(Request $request, Invoice $invoice, InvoiceService $service)
    {
        $data = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'reference' => 'nullable|string|max:64',
        ])->validate();

        $payment = $service->recordPayment($invoice, (float) $data['amount'], [
            'method' => 'manual',
            'mpesa_receipt' => $data['reference'] ?? null,
        ]);

        return response()->json(['payment' => $payment, 'invoice' => $invoice->fresh()]);
    }
}
