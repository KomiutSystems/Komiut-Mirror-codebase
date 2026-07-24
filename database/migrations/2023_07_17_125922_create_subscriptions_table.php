<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SACCO billing schema.
 *
 * A `subscription` puts a SACCO on a plan and carries the recurring-invoice
 * cursor (`next_invoice_date`). Each period the generator raises an `invoice`
 * with `invoice_items`; the SACCO settles it by paying the `invoice_number` as
 * the M-Pesa account reference, recorded as `invoice_payments`.
 *
 * The invoice tables live in this same migration on purpose: the billing schema
 * is built in place while still on local (no shipped incremental DB), and ships
 * fresh to the new environment. If a target already has data, add a real new
 * migration instead — editing an applied migration is a no-op there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('sacco_id')->index();
            $table->unsignedInteger('subscription_type_id');
            $table->double('amount')->default(0);           // legacy/manual override, unused by the generator
            $table->double('previous_balance')->default(0); // manual adjustment field (not auto-carried)
            $table->date('starts_on')->nullable();
            $table->date('next_invoice_date')->nullable()->index(); // "who is due" cursor
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('status_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sacco_id')->index();
            $table->unsignedInteger('subscription_id')->nullable();
            $table->string('invoice_number')->unique();       // human ref + M-Pesa account number
            $table->string('status')->default('draft')->index(); // App\Enums\InvoiceStatus
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date')->nullable();
            $table->string('currency', 3)->default('KES');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);        // 0 for now; eTIMS later
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            // one invoice per SACCO per billing period → generation is idempotent
            $table->unique(['sacco_id', 'period_start']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->string('description');
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->unsignedInteger('sacco_id')->index();
            $table->decimal('amount', 12, 2);
            $table->string('method')->default('mpesa');
            $table->string('mpesa_receipt')->nullable()->unique(); // dedupe replayed C2B callbacks
            $table->string('phone')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
    }
};
