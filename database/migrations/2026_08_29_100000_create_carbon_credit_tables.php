<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Sacco;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carbon credits: a PLATFORM reward for travelling by matatu through the app.
 *
 * Deliberately not a second loyalty programme. `loyalty_*` is per-SACCO, funded
 * by that SACCO, and spent on that SACCO's buses. This is the platform's own
 * reward, earned across every SACCO and brand and spent with partners the
 * platform signs — a Safaricom bundle, a supermarket voucher, or a free ride a
 * SACCO chooses to fund. A passenger holds ONE balance, which is the point:
 * riding a komiut bus today and a safiri bus tomorrow builds the same balance,
 * so there is no sacco_id and no brand column on the account.
 *
 * WHY CENTS. Fares are 30–150 KSh against a 1,000 KSh credit, so the remainder
 * is carried between rides — an accumulator that is added to thousands of times.
 * Doubles drift; integer cents do not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carbon_credit_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->unique()->constrained()->cascadeOnDelete();

            $table->unsignedInteger('credits')->default(0);

            // Shillings-in-cents carried toward the next credit. Always less
            // than one credit's worth: the service mints whenever it is not.
            $table->unsignedBigInteger('progress_cents')->default(0);

            // Never decreases; what the passenger has travelled, for reporting.
            $table->unsignedBigInteger('lifetime_spend_cents')->default(0);

            $table->timestamps();
        });

        Schema::create('carbon_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();

            // Signed: + earned, − redeemed. The account is the running total.
            $table->integer('credits');
            $table->string('type', 20);

            // The travel that produced this row, in cents. Zero on a redemption.
            $table->unsignedBigInteger('spend_cents')->default(0);

            // ONE earn row per booking, enforced by the database rather than by
            // the service remembering to check: BookingPaid can fire more than
            // once for the same booking, and a re-credited ride is money.
            $table->foreignIdFor(Booking::class)->nullable()->constrained()->nullOnDelete();

            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        // Partial unique: only EARN rows are one-per-booking. A booking can be
        // reversed and re-earned, and redemptions carry no booking at all.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'CREATE UNIQUE INDEX carbon_credit_transactions_earned_booking_unique
                 ON carbon_credit_transactions (booking_id) WHERE type = \'earned\' AND booking_id IS NOT NULL'
            );
        } else {
            Schema::table('carbon_credit_transactions', function (Blueprint $table) {
                $table->unique(['booking_id', 'type'], 'carbon_credit_transactions_earned_booking_unique');
            });
        }

        Schema::create('carbon_credit_rewards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('partner', 30);
            $table->text('description')->nullable();
            $table->unsignedInteger('credits_required');

            // Set only for a SACCO-funded reward (a free ride). Null means the
            // platform or an outside partner funds it.
            $table->foreignIdFor(Sacco::class)->nullable()->constrained()->nullOnDelete();

            // Null is unlimited. Decremented as redemptions are claimed.
            $table->unsignedInteger('stock')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'credits_required']);
        });

        Schema::create('carbon_credit_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignId('carbon_credit_reward_id')->constrained()->cascadeOnDelete();

            // Copied, not read through the reward: repricing a reward later must
            // not rewrite what somebody already paid for it.
            $table->unsignedInteger('credits_spent');

            $table->string('status', 20)->default('pending');

            // The partner's own identifier once fulfilled — a Safaricom bundle
            // reference, a voucher code, the booking a free ride was applied to.
            $table->string('reference')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carbon_credit_redemptions');
        Schema::dropIfExists('carbon_credit_rewards');
        Schema::dropIfExists('carbon_credit_transactions');
        Schema::dropIfExists('carbon_credit_accounts');
    }
};
