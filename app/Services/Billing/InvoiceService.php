<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Subscription;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SACCO subscription billing.
 *
 * Generation is idempotent (one invoice per SACCO per period_start, enforced by
 * a unique DB index and re-checked here). All money is whole-KES: M-Pesa settles
 * integer amounts, so every component is rounded to avoid cents that can never
 * be paid off. Payments are advisory — they are recorded, then the invoice is
 * recomputed from the sum of its payments; a payload never sets status directly.
 */
class InvoiceService
{
    /** Days after issue an invoice is due. */
    private const NET_DAYS = 14;

    /**
     * Raise invoices for every active subscription whose next_invoice_date has
     * arrived. One invoice per subscription per run; a subscription that is many
     * periods behind catches up over successive daily runs.
     *
     * @return array<int, Invoice>
     */
    public function generateDueInvoices(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ? $asOf->copy() : now())->startOfDay();
        $created = [];

        Subscription::query()
            ->where('is_active', true)
            ->whereNotNull('next_invoice_date')
            ->whereDate('next_invoice_date', '<=', $asOf)
            ->with('plan')
            ->chunkById(200, function ($subs) use (&$created): void {
                foreach ($subs as $sub) {
                    try {
                        $invoice = $this->generateFor($sub);
                        if ($invoice) {
                            $created[] = $invoice;
                        }
                    } catch (\Throwable $e) {
                        Log::error('invoice generation failed', [
                            'subscription_id' => $sub->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $created;
    }

    /**
     * Generate the invoice for one subscription's current due period. Returns
     * null when the period is already billed, the plan is missing, or the
     * computed total is zero (nothing to charge) — the cursor is still advanced.
     */
    public function generateFor(Subscription $sub, ?Carbon $periodStart = null): ?Invoice
    {
        $plan = $sub->plan;
        if (! $plan) {
            return null;
        }

        $periodStart = ($periodStart ?? $sub->next_invoice_date ?? $sub->starts_on ?? now())
            ->copy()->startOfDay();
        $periodEnd = $this->advance($periodStart, (string) $plan->billing_cycle)->copy()->subDay();

        // Idempotency: one invoice per SACCO per period_start.
        $exists = Invoice::withoutGlobalScopes()
            ->where('sacco_id', $sub->sacco_id)
            ->whereDate('period_start', $periodStart)
            ->exists();
        if ($exists) {
            $this->advanceCursor($sub, $periodStart, (string) $plan->billing_cycle);

            return null;
        }

        $vehicleCount = Vehicle::withoutGlobalScopes()
            ->where('sacco_id', $sub->sacco_id)
            ->where('status', true)
            ->count();
        $billableVehicles = $plan->vehicle_cap
            ? min($vehicleCount, (int) $plan->vehicle_cap)
            : $vehicleCount;

        $items = [];
        $baseFee = $this->kes($plan->base_fee);
        if ($baseFee > 0) {
            $items[] = [
                'description' => "Base subscription — {$plan->name}",
                'quantity' => 1,
                'unit_price' => $baseFee,
                'amount' => $baseFee,
            ];
        }
        $perVehicle = $this->kes($plan->per_vehicle_fee);
        if ($perVehicle > 0 && $billableVehicles > 0) {
            $items[] = [
                'description' => "Per-vehicle fee — {$billableVehicles} vehicle(s)",
                'quantity' => $billableVehicles,
                'unit_price' => $perVehicle,
                'amount' => $this->kes($perVehicle * $billableVehicles),
            ];
        }

        $subtotal = $this->kes(array_sum(array_column($items, 'amount')));
        $tax = 0.0;                       // internal invoices for now; eTIMS later
        $total = $subtotal + $tax;

        if ($total <= 0) {
            $this->advanceCursor($sub, $periodStart, (string) $plan->billing_cycle);

            return null;
        }

        return DB::transaction(function () use ($sub, $plan, $periodStart, $periodEnd, $items, $subtotal, $tax, $total) {
            $invoice = Invoice::create([
                'sacco_id' => $sub->sacco_id,
                'subscription_id' => $sub->id,
                'invoice_number' => $this->invoiceNumber($sub->sacco_id, $periodStart),
                'status' => InvoiceStatus::Issued,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'due_date' => now()->startOfDay()->addDays(self::NET_DAYS),
                'currency' => $plan->currency ?: 'KES',
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $total,
                'amount_paid' => 0,
                'balance' => $total,
                'issued_at' => now(),
            ]);

            foreach ($items as $item) {
                $invoice->items()->create($item);
            }

            $this->advanceCursor($sub, $periodStart, (string) $plan->billing_cycle);

            return $invoice->load('items');
        });
    }

    /**
     * Record a payment against an invoice and recompute its state. Idempotent on
     * mpesa_receipt (a replayed callback returns the existing payment). Ignores
     * non-positive amounts and payments to a void invoice.
     */
    public function recordPayment(Invoice $invoice, float $amount, array $meta = []): ?InvoicePayment
    {
        $amount = $this->kes($amount);
        if ($amount <= 0) {
            return null;
        }

        $receipt = $meta['mpesa_receipt'] ?? null;

        return DB::transaction(function () use ($invoice, $amount, $meta, $receipt) {
            // Lock the invoice so concurrent callbacks can't race the recompute.
            $locked = Invoice::withoutGlobalScopes()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->first();
            if (! $locked) {
                return null;
            }
            if ($locked->status === InvoiceStatus::Void) {
                Log::warning('payment for void invoice ignored', [
                    'invoice' => $locked->invoice_number,
                    'receipt' => $receipt,
                ]);

                return null;
            }

            if ($receipt) {
                $dupe = InvoicePayment::withoutGlobalScopes()->where('mpesa_receipt', $receipt)->first();
                if ($dupe) {
                    return $dupe; // callback replay — already recorded
                }
            }

            $payment = $locked->payments()->create([
                'sacco_id' => $locked->sacco_id,
                'amount' => $amount,
                'method' => $meta['method'] ?? 'mpesa',
                'mpesa_receipt' => $receipt,
                'phone' => $meta['phone'] ?? null,
                'paid_at' => $meta['paid_at'] ?? now(),
                'raw' => $meta['raw'] ?? null,
            ]);

            $this->recompute($locked);

            return $payment;
        });
    }

    /** Find the invoice a C2B account reference (BillRefNumber) points at. */
    public function matchByReference(?string $reference): ?Invoice
    {
        $reference = $reference !== null ? trim($reference) : '';
        if ($reference === '') {
            return null;
        }

        return Invoice::withoutGlobalScopes()->where('invoice_number', $reference)->first();
    }

    /** Flag issued/partial invoices past their due date and still owing. */
    public function markOverdue(?Carbon $asOf = null): int
    {
        $asOf = ($asOf ? $asOf->copy() : now())->startOfDay();

        return Invoice::withoutGlobalScopes()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Partial->value])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $asOf)
            ->where('balance', '>', 0)
            ->update(['status' => InvoiceStatus::Overdue->value]);
    }

    /**
     * Recompute amount_paid / balance / status from the invoice's payments.
     * Balance is clamped at zero (an overpayment can't drive it negative) and a
     * void invoice is never resurrected.
     */
    protected function recompute(Invoice $invoice): void
    {
        $paid = $this->kes((float) $invoice->payments()->sum('amount'));
        $total = (float) $invoice->total;
        $balance = max(0.0, $total - $paid);

        $invoice->amount_paid = $paid;
        $invoice->balance = $balance;

        if ($invoice->status !== InvoiceStatus::Void) {
            if ($paid <= 0) {
                // leave issued/overdue as-is
            } elseif ($balance <= 0) {
                $invoice->status = InvoiceStatus::Paid;
                $invoice->paid_at = $invoice->paid_at ?? now();
            } else {
                $invoice->status = InvoiceStatus::Partial;
            }
        }

        $invoice->save();
    }

    /** Move the subscription's cursor to the next period if it hasn't already. */
    protected function advanceCursor(Subscription $sub, Carbon $periodStart, string $cycle): void
    {
        $next = $this->advance($periodStart, $cycle);
        if ($sub->next_invoice_date === null || $sub->next_invoice_date->lte($periodStart)) {
            $sub->next_invoice_date = $next;
            $sub->save();
        }
    }

    /** The period after $date for a billing cycle. */
    protected function advance(Carbon $date, string $cycle): Carbon
    {
        return match ($cycle) {
            'quarterly' => $date->copy()->addMonthsNoOverflow(3),
            'annually', 'yearly' => $date->copy()->addYearNoOverflow(),
            default => $date->copy()->addMonthNoOverflow(),
        };
    }

    /** Human/M-Pesa reference, unique per (SACCO, period). */
    protected function invoiceNumber(int $saccoId, Carbon $periodStart): string
    {
        return sprintf('INV-%d-%s', $saccoId, $periodStart->format('Ym'));
    }

    /** Round to whole KES (M-Pesa never settles fractional shillings). */
    protected function kes(float|int|string|null $value): float
    {
        return round((float) $value);
    }
}
