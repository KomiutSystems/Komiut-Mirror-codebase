<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A driver who said yes to opening a bank account while being onboarded.
 *
 * This is a lead list handed to a partner bank, not an application: no account
 * numbers, no balances, nothing the bank hasn't asked the driver for directly.
 * The partner is derived from the brand (see App\Enums\BankPartner), so `bank`
 * is recorded rather than chosen — it is denormalised here only so a lead stays
 * attributable after a brand's partner changes.
 *
 * `status` tracks the lead through the bank's hands (new → shared → contacted →
 * opened|declined); (brand, status) is the index the export query filters on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_bank_leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('brand');
            $table->string('bank');
            $table->string('preferred_branch')->nullable();
            // The matatu's seat count: the bank sizes the account by expected takings.
            $table->unsignedInteger('vehicle_capacity')->nullable();
            // Nullable so a lead backfilled from another source is still storable.
            $table->timestamp('opted_in_at')->nullable();
            $table->string('status')->default('new');
            $table->timestamps();

            $table->index(['brand', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_bank_leads');
    }
};
