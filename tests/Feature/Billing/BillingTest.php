<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Sacco;
use App\Models\Subscription;
use App\Models\SubscriptionType;
use App\Services\Billing\InvoiceService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * SACCO subscription billing:
 * - App\Services\Billing\InvoiceService (generation, payment, overdue)
 * - pricing = base_fee + per_vehicle_fee × active vehicles (capped)
 * - the M-Pesa C2B safety net (receipt dedupe, amount clamp, void guard)
 */
final class BillingTest extends QueueTestCase
{
    private function service(): InvoiceService
    {
        return app(InvoiceService::class);
    }

    private function plan(array $overrides = []): SubscriptionType
    {
        return SubscriptionType::create(array_merge([
            'name' => 'Standard',
            'billing_cycle' => 'monthly',
            'base_fee' => 500,
            'per_vehicle_fee' => 100,
            'currency' => 'KES',
            'is_active' => true,
        ], $overrides));
    }

    private function subscribe(Sacco $sacco, SubscriptionType $plan, ?Carbon $startsOn = null): Subscription
    {
        $startsOn = $startsOn ?? now()->startOfDay();

        return Subscription::withoutGlobalScopes()->create([
            'sacco_id' => $sacco->id,
            'subscription_type_id' => $plan->id,
            'starts_on' => $startsOn,
            'next_invoice_date' => $startsOn,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function invoice_is_priced_by_base_plus_active_vehicles(): void
    {
        $world = $this->makeWorld();                 // 1 active vehicle
        $sacco = $world['sacco'];
        $this->makeVehicle($sacco, $world['owner'], $world['seat']);            // +1 active
        $this->makeVehicle($sacco, $world['owner'], $world['seat']);            // +1 active
        $this->makeVehicle($sacco, $world['owner'], $world['seat'])->update(['status' => false]); // inactive

        $this->subscribe($sacco, $this->plan());     // 500 base + 100/vehicle

        $created = $this->service()->generateDueInvoices();

        $this->assertCount(1, $created);
        $invoice = Invoice::withoutGlobalScopes()->firstOrFail();
        $this->assertEquals(800.0, (float) $invoice->total);   // 500 + 3×100
        $this->assertEquals(800.0, (float) $invoice->balance);
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertCount(2, $invoice->items);                // base line + per-vehicle line

        $this->assertTrue(
            Subscription::withoutGlobalScopes()->firstOrFail()->next_invoice_date->gt(now()),
            'the invoice cursor should advance to the next period'
        );
    }

    #[Test]
    public function generation_is_idempotent_per_period(): void
    {
        $world = $this->makeWorld();
        $this->subscribe($world['sacco'], $this->plan());

        $this->service()->generateDueInvoices();
        $this->service()->generateDueInvoices();     // re-run same day

        $this->assertSame(1, Invoice::withoutGlobalScopes()->count());
    }

    #[Test]
    public function vehicle_cap_limits_the_per_vehicle_charge(): void
    {
        $world = $this->makeWorld();                 // 1 vehicle
        $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);   // 2 total
        $this->subscribe($world['sacco'], $this->plan(['base_fee' => 0, 'vehicle_cap' => 1]));

        $this->service()->generateDueInvoices();

        $this->assertEquals(100.0, (float) Invoice::withoutGlobalScopes()->firstOrFail()->total);
    }

    #[Test]
    public function partial_then_full_payment_settles_the_invoice(): void
    {
        $world = $this->makeWorld();
        $this->subscribe($world['sacco'], $this->plan());
        $this->service()->generateDueInvoices();
        $invoice = Invoice::withoutGlobalScopes()->firstOrFail();   // total 600 (500 + 1×100)

        $this->service()->recordPayment($invoice, 200, ['mpesa_receipt' => 'R1']);
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Partial, $invoice->status);
        $this->assertEquals(400.0, (float) $invoice->balance);

        $this->service()->recordPayment($invoice, 400, ['mpesa_receipt' => 'R2']);
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertEquals(0.0, (float) $invoice->balance);
        $this->assertNotNull($invoice->paid_at);
    }

    #[Test]
    public function duplicate_mpesa_receipt_does_not_double_credit(): void
    {
        $world = $this->makeWorld();
        $this->subscribe($world['sacco'], $this->plan());
        $this->service()->generateDueInvoices();
        $invoice = Invoice::withoutGlobalScopes()->firstOrFail();

        $this->service()->recordPayment($invoice, 200, ['mpesa_receipt' => 'DUP']);
        $this->service()->recordPayment($invoice, 200, ['mpesa_receipt' => 'DUP']); // replay

        $invoice->refresh();
        $this->assertEquals(200.0, (float) $invoice->amount_paid);
        $this->assertSame(1, $invoice->payments()->count());
    }

    #[Test]
    public function overpayment_never_drives_balance_negative(): void
    {
        $world = $this->makeWorld();
        $this->subscribe($world['sacco'], $this->plan());
        $this->service()->generateDueInvoices();
        $invoice = Invoice::withoutGlobalScopes()->firstOrFail();   // total 600

        $this->service()->recordPayment($invoice, 1000, ['mpesa_receipt' => 'BIG']);

        $invoice->refresh();
        $this->assertEquals(0.0, (float) $invoice->balance);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
    }

    #[Test]
    public function payment_to_a_void_invoice_is_ignored(): void
    {
        $world = $this->makeWorld();
        $this->subscribe($world['sacco'], $this->plan());
        $this->service()->generateDueInvoices();
        $invoice = Invoice::withoutGlobalScopes()->firstOrFail();
        $invoice->update(['status' => InvoiceStatus::Void, 'balance' => 0]);

        $payment = $this->service()->recordPayment($invoice, 600, ['mpesa_receipt' => 'V1']);

        $this->assertNull($payment);
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
    }

    #[Test]
    public function overdue_invoices_are_flagged(): void
    {
        $world = $this->makeWorld();
        $this->subscribe($world['sacco'], $this->plan());
        $this->service()->generateDueInvoices();
        Invoice::withoutGlobalScopes()->firstOrFail()->update(['due_date' => now()->subDay()]);

        $count = $this->service()->markOverdue();

        $this->assertSame(1, $count);
        $this->assertSame(InvoiceStatus::Overdue, Invoice::withoutGlobalScopes()->firstOrFail()->status);
    }

    #[Test]
    public function c2b_reference_matches_the_invoice(): void
    {
        $world = $this->makeWorld();
        $this->subscribe($world['sacco'], $this->plan());
        $this->service()->generateDueInvoices();
        $invoice = Invoice::withoutGlobalScopes()->firstOrFail();

        $matched = $this->service()->matchByReference($invoice->invoice_number);

        $this->assertNotNull($matched);
        $this->assertSame($invoice->id, $matched->id);
        $this->assertNull($this->service()->matchByReference('INV-nope'));
    }
}
