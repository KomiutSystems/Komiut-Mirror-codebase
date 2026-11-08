<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Mpesa;
use App\Models\Sacco;
use App\Models\Vehicle;
use App\Models\Wallet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The wallet ledger: append-only, one row per movement.
 *
 * `balance_after_cents` is stored rather than derived, which is a deliberate
 * duplication of `wallets.balance_cents`. It is what makes the float auditable:
 * the ledger can be replayed in order and the running total compared against
 * the wallet row, so a bug that loses or invents money is DETECTABLE rather
 * than merely regrettable. Any licence conversation about holding customer
 * funds will ask to see exactly this, and it cannot be reconstructed after the
 * fact.
 *
 * TWO UNIQUE COLUMNS, EACH DOING A DIFFERENT JOB:
 *
 *   reference  -- the general idempotency key. A retried credit or debit with
 *                 the same reference replays the stored row instead of moving
 *                 money twice. Every write path must supply one.
 *
 *   mpesa_id   -- how the two top-up rails stay safe against EACH OTHER. STK
 *                 push and the C2B confirmation webhook can both fire for the
 *                 same payment, and they arrive by different routes with no
 *                 shared lock. Both resolve to a row in `mpesas`, so making
 *                 that FK unique means whichever lands first credits the wallet
 *                 and the second hits the constraint and becomes a no-op. This
 *                 is the whole cross-rail design and it needs no coordination.
 *                 Nullable, and Postgres permits many NULLs in a unique index,
 *                 so non-top-up rows are unaffected.
 *
 * `financier` is stamped at spend time from the vehicle, NOT from the wallet.
 * Settlement splits per bank and the two banks reconcile separately, so the
 * bank that must be paid for a ride is a property of the bus, not of where the
 * passenger's float happens to sit. Without it on the row, a month of mixed
 * rides cannot be apportioned afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Wallet::class)->constrained()->cascadeOnDelete();

            // App\Enums\WalletEntryKind -- the app's `kind` field verbatim.
            $table->string('kind', 20);

            // SIGNED: positive credits, negative debits. Direction is the sign,
            // never the kind (see the enum's note), so a reversal of a refund
            // is representable.
            $table->bigInteger('amount_cents');

            $table->bigInteger('balance_after_cents');

            // What the passenger reads. Stored rather than computed at render
            // time so that history does not rewrite itself when a label, a
            // route name or a SACCO name later changes.
            $table->string('title');
            $table->string('detail')->nullable();

            // Idempotency. See the class note.
            $table->string('reference', 120)->unique();

            // What this movement was about. All nullable: a top-up has no
            // vehicle, an adjustment has neither.
            $table->foreignIdFor(Booking::class)->nullable();
            $table->foreignIdFor(Vehicle::class)->nullable();
            $table->foreignIdFor(Sacco::class)->nullable();

            // 'NCBA' | 'coop-bank' -- App\Enums\Financier, from the vehicle.
            $table->string('financier')->nullable();

            // The cross-rail guard. See the class note.
            $table->foreignIdFor(Mpesa::class)->nullable()->unique();

            // Who moved it, when it was not the passenger. Null = the system.
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            // The passenger's own history, newest first -- the only read path
            // GET /wallet/transactions has.
            $table->index(['wallet_id', 'id']);

            // The monthly figures on GET /wallet, and the settlement sweep.
            $table->index(['wallet_id', 'kind', 'created_at']);

            // Settlement: everything owed to one bank for a period.
            $table->index(['financier', 'created_at']);
            $table->index(['sacco_id', 'created_at']);
        });

        // A zero-value movement is not a movement. It would show the passenger
        // a history row explaining nothing and would let a buggy caller
        // "succeed" at doing nothing, which is harder to notice than a failure.
        // Carbon credits deliberately DO record zero-delta rows, but those
        // explain progress toward a credit; a wallet has no such accumulator.
        DB::statement('ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_amount_not_zero CHECK (amount_cents <> 0)');

        // The ledger may never claim a negative balance: see the matching
        // constraint on `wallets`.
        DB::statement('ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_balance_after_not_negative CHECK (balance_after_cents >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
