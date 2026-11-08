<?php

declare(strict_types=1);

use App\Models\Sacco;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What Komiut owes a SACCO for the wallet fares its buses carried.
 *
 * THE LIABILITY THIS TABLE MAKES VISIBLE. A wallet fare takes money from a
 * passenger's float balance and gives the ride to a SACCO's bus. The money is
 * in a Komiut bank account; the SACCO has earned it and has not been paid. Until
 * this table existed that obligation lived nowhere -- `wallet:reconcile` could
 * only report `spent` and assume `settled = 0`, which is true exactly until the
 * first payout and then silently wrong forever.
 *
 * PER (SACCO, FINANCIER, BUSINESS DAY), and the financier is not optional.
 * App\Enums\Financier records why: NICCO MOVERS alone runs 126 NCBA-financed
 * vehicles and 54 Co-op ones, and "the two banks reconcile against it
 * separately". One settlement row per SACCO per day would mix two banks' money
 * into a single payout neither could reconcile. The fare carries its bank from
 * the VEHICLE (wallet_transactions.financier), so the split is recorded at the
 * moment of the ride and not reconstructed later.
 *
 * THE BUSINESS DAY IS THE 03:00-EAT ONE (App\Support\BusinessDay), the same
 * boundary the takings, the summaries and wallet:reconcile all use. A payout
 * period that disagreed with the takings reports would be unarguable with.
 *
 * STATUS IS A ONE-WAY RATCHET: pending -> approved -> paid, or -> failed and
 * back to approved for a retry. `paid` is terminal and the row becomes
 * immutable, because the only thing worse than not paying a SACCO is paying
 * them twice from a float that is somebody else's money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_settlements', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Sacco::class)->constrained()->cascadeOnDelete();

            // App\Enums\Financier: 'NCBA' | 'coop-bank'. NULL means the fares in
            // this row are on buses no bank finances -- still owed, still paid,
            // just not reconciled by a bank.
            $table->string('financier')->nullable();

            // The 03:00-EAT business day this covers. `period_end` is stored
            // rather than derived so a later change to the boundary cannot
            // silently restate what an already-paid row covered.
            $table->timestamp('period_start');
            $table->timestamp('period_end');

            $table->bigInteger('gross_cents');
            $table->unsignedInteger('fare_count');

            $table->string('status', 16)->default('pending');

            /**
             * The idempotency key for the PAYOUT, minted at accrual and never
             * reused. A disbursement rail is asked to send `reference`, so a
             * retry after a timeout -- where the money may already have left --
             * is the same request to the rail rather than a second payment.
             */
            $table->string('reference', 120)->unique();

            // What the rail gave back: an M-Pesa B2B TransactionID, or the bank
            // transfer reference a human typed in after paying manually.
            $table->string('payout_reference')->nullable();
            $table->string('payout_rail', 24)->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->string('failure_reason')->nullable();

            $table->timestamps();

            // ONE ACCRUAL PER SACCO PER BANK PER DAY. Without this a second
            // accrual run would mint a parallel row for the same period and the
            // same fares would be owed twice -- the fares themselves are
            // protected by wallet_transactions.wallet_settlement_id, but a
            // duplicate empty row would still be payable.
            $table->unique(['sacco_id', 'financier', 'period_start'], 'wallet_settlements_period_unique');

            // The payout queue: what is waiting, oldest first.
            $table->index(['status', 'period_start']);
        });

        // A settlement for nothing is not a settlement. The accrual skips empty
        // groups; this stops a hand-written row claiming a payout of zero.
        DB::statement('ALTER TABLE wallet_settlements ADD CONSTRAINT wallet_settlements_gross_positive CHECK (gross_cents > 0)');

        Schema::table('wallet_transactions', function (Blueprint $table) {
            /**
             * Which settlement has claimed this fare. NULL = not yet accrued.
             *
             * THIS IS WHAT MAKES A FARE SETTLEABLE EXACTLY ONCE: the accrual
             * only ever selects rows where this is NULL, and sets it inside the
             * same transaction that writes the settlement. A second run over the
             * same day finds nothing left to claim.
             *
             * Deliberately NOT unique -- many fares belong to one settlement.
             */
            $table->unsignedBigInteger('wallet_settlement_id')->nullable()->after('financier');

            // The accrual's own query: unsettled fares in a window, by bank.
            $table->index(['wallet_settlement_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex(['wallet_settlement_id', 'kind']);
            $table->dropColumn('wallet_settlement_id');
        });

        Schema::dropIfExists('wallet_settlements');
    }
};
