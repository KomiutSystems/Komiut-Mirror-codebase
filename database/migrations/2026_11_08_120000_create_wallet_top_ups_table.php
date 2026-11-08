<?php

declare(strict_types=1);

use App\Enums\WalletTopUpStatus;
use App\Models\Mpesa;
use App\Models\Wallet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One attempt to load money into a wallet.
 *
 * SEPARATE FROM THE LEDGER, deliberately. `wallet_transactions` records money
 * that HAS moved and is append-only; this records an INTENTION that may never
 * be fulfilled, and its status changes. Folding the two together would mean
 * either a ledger row that is edited after the fact, or a pending row that
 * counts toward a balance before any money exists.
 *
 * The passenger polls this (GET /wallet/top-ups/{id}) while the prompt is on
 * their handset, which is why `status` and `failure_reason` live here rather
 * than being inferred from the absence of a ledger row.
 *
 * `wallet_transaction_id` is the join to the money once it lands. Null until
 * then, and set exactly once: it is what stops a second settlement of the same
 * attempt from crediting again, independently of the `mpesa_id` guard on the
 * ledger itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_top_ups', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Wallet::class)->constrained()->cascadeOnDelete();

            $table->bigInteger('amount_cents');

            // App\Enums\WalletTopUpRail -- the app's `source`.
            $table->string('rail', 16);

            $table->string('status', 16)->default(WalletTopUpStatus::Pending->value);

            // The handset the prompt went to. Stored because the passenger may
            // top up from a different number than they signed up with, and a
            // failed top-up is unexplainable without knowing which phone was
            // asked.
            $table->string('phone', 20)->nullable();

            /**
             * THE IDEMPOTENCY KEY FROM THE CLIENT. The app already mints one
             * (core/network/idempotency.dart) and sends it as a header, so a
             * tapped-twice Top Up button must produce ONE prompt, not two.
             * Unique, so the second request returns the first attempt.
             */
            $table->string('idempotency_key', 120)->unique();

            /**
             * The rail's own handle on the attempt. Daraja calls it
             * CheckoutRequestID; NCBA calls it TransactionID. One column
             * because only one rail is ever in play per row, and `rail` says
             * which vocabulary it is.
             */
            $table->string('rail_reference')->nullable();

            /**
             * The secret in the callback URL, NOT the row id.
             *
             * Follows the existing push: `stk/push/response/{nonce}` replaced a
             * sequential `?booking_id=` for exactly this reason. The callback
             * is unauthenticated -- Safaricom posts it -- so a guessable path
             * lets anyone mark another passenger's top-up cancelled. It cannot
             * mint money either way (only the C2B confirmation credits), but it
             * can lie to a passenger about their own.
             */
            $table->string('callback_nonce', 64)->nullable()->unique();

            /**
             * What the callback CLAIMED, stored before it is verified.
             *
             * Unverified on purpose. An STK payment reaches us only through its
             * callback, so if stkQuery is unreachable at that moment (Daraja
             * 500s routinely, and DarajaClient::stkQuery returns null on any
             * 5xx) the receipt is the only trace that a payment may exist.
             * Throwing it away would leave the recovery sweep nothing to work
             * from and the passenger's money stranded.
             *
             * NEVER credited from directly -- wallet_transactions.mpesa_id is
             * still the only thing that moves money, and only after stkQuery
             * confirms.
             */
            $table->string('rail_receipt')->nullable();
            $table->timestamp('rail_paid_at')->nullable();

            // What the money turned out to be, once it arrives.
            $table->foreignIdFor(Mpesa::class)->nullable();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();

            $table->string('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // The passenger's own list, newest first.
            $table->index(['wallet_id', 'id']);

            // The sweep: everything still waiting to be resolved.
            $table->index(['status', 'created_at']);

            // Looking an attempt up from a rail callback or a poll.
            $table->index('rail_reference');
        });

        // A top-up for nothing is not a top-up. The service checks the
        // configured minimum too, but this is the floor no caller can get under.
        DB::statement('ALTER TABLE wallet_top_ups ADD CONSTRAINT wallet_top_ups_amount_positive CHECK (amount_cents > 0)');

        /**
         * ONE SETTLEMENT PER ATTEMPT, enforced by the database.
         *
         * The ledger's unique `mpesa_id` already stops one M-Pesa payment
         * crediting twice. This is the other direction: it stops one ATTEMPT
         * being credited by two different payments, which is what a passenger
         * paying twice against a single prompt would otherwise produce -- the
         * second payment is real money and must become its own top-up, not a
         * second settlement of this one.
         */
        DB::statement('CREATE UNIQUE INDEX wallet_top_ups_one_settlement ON wallet_top_ups (wallet_transaction_id) WHERE wallet_transaction_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_top_ups');
    }
};
