<?php

declare(strict_types=1);

use App\Enums\WalletStatus;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One prepaid balance per passenger.
 *
 * PLATFORM-WIDE, NOT PER-SACCO -- deliberately unlike `loyalty_accounts`, which
 * is UNIQUE (user_id, sacco_id) because a SACCO's points are that SACCO's
 * liability. Wallet money is spendable on any bus, so there is exactly one row
 * per passenger and NO SaccoScope or BrandScope on the model. Carbon credits
 * already set this precedent ("one balance across every SACCO and brand").
 *
 * `float_bank` is the consequence of that choice. The money itself sits in a
 * real bank account -- NCBA for Komiut, Co-operative for 2Safiri, the mapping
 * App\Enums\BankPartner already encodes -- so the row records WHERE a
 * passenger's float is held. It is needed because one balance spent across any
 * bus cuts straight across how the two banks reconcile: NICCO MOVERS alone runs
 * 126 NCBA-financed vehicles and 54 Co-op ones, and per App\Enums\Financier
 * "the two banks reconcile against it separately". A passenger whose float sits
 * at NCBA riding a Co-op-financed bus creates an inter-bank obligation, and
 * nothing can settle that later unless it was recorded here at the start.
 * Nullable: an unmapped brand must not block a wallet from existing (see the
 * model), and the reconciliation sweep flags nulls rather than guessing.
 *
 * MONEY IS CENTS. See App\Support\Shillings for why, and for the one place the
 * conversion happens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();

            // One wallet per passenger, enforced by the database rather than by
            // a firstOrCreate race. Two concurrent first-ever credits both try
            // to insert; the loser catches the violation and locks the winner's
            // row (WalletService::lock).
            $table->foreignIdFor(User::class)->unique()->constrained()->cascadeOnDelete();

            // BIGINT and SIGNED on purpose. Signed so that a bug shows up as a
            // negative balance -- visible, alarming, reconcilable -- instead of
            // wrapping around into a fortune, which is what an unsigned column
            // would do on an underflow.
            $table->bigInteger('balance_cents')->default(0);

            $table->string('status', 20)->default(WalletStatus::Active->value);

            // bcrypt of a 4-digit PIN. Hidden on the model; never logged, never
            // returned. Nullable because a PIN is only required above the tap
            // limit, so a wallet is usable for small fares before one is set.
            //
            // THE FAILED-ATTEMPT COUNTER IS NOT HERE. It lives in the rate
            // limiter (Redis), because every caller that checks a PIN is inside
            // a transaction it is about to roll back by refusing -- a counter
            // written in that transaction rolls back with it, so the lockout
            // would never fire and a 4-digit PIN would be brute-forceable at
            // full speed. Cache eviction losing a lockout early is the right
            // trade against that.
            $table->string('pin_hash')->nullable();

            // The ceiling under which a tap needs no PIN, per passenger.
            $table->bigInteger('tap_limit_cents');

            // 'ncba' | 'coop' -- App\Enums\BankPartner. See the class note.
            $table->string('float_bank', 16)->nullable();

            $table->timestamp('frozen_at')->nullable();
            $table->string('frozen_reason')->nullable();

            $table->timestamps();

            // The float-exposure query: which wallets are holding money.
            $table->index(['status', 'balance_cents']);
            $table->index('float_bank');
        });

        // THE LAST LINE OF DEFENCE, and the reason it is a database constraint
        // and not only a service check: every debit in WalletService runs under
        // a row lock and refuses an overdraw, but a constraint also catches a
        // future caller that forgets the service, a bad manual UPDATE during an
        // incident, and a migration that backfills wrong. A passenger wallet
        // going negative is money the platform cannot collect, so it should be
        // impossible to write, not merely unlikely.
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_balance_not_negative CHECK (balance_cents >= 0)');
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_tap_limit_not_negative CHECK (tap_limit_cents >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
