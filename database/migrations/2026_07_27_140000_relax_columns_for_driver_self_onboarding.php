<?php

declare(strict_types=1);

use App\Models\Seat;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the users/vehicles tables survive a driver onboarded on the street.
 *
 * A matatu driver is signed up in person by a marketing agent, with only what
 * they carry: a name, a phone, an ID card and the plate on the vehicle behind
 * them. Three columns assume more than that:
 *
 *  - users.email is NOT NULL + unique, but most drivers have no email. A
 *    synthesised placeholder would be worse than a NULL: it occupies the unique
 *    index and can never be told apart from a real address later.
 *  - vehicles.user_id (the owner) and vehicles.seat_id (the seat map) are set by
 *    the SACCO in the dashboard. At onboarding the SACCO usually has no account
 *    at all, so neither is knowable — they are filled in when it claims the fleet.
 *
 * users.id_number is added here rather than on driver_bank_leads: the bank asks
 * for it, but it identifies the driver and is collected whether or not they opt
 * in to a bank account.
 *
 * The ->change() calls follow the precedent set by the type/social migration,
 * which relaxed the (also unique) phone column on the same table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('id_number')->nullable()->after('phone')->index();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->foreignIdFor(User::class)->nullable()->change();
            $table->foreignIdFor(Seat::class)->nullable()->change();
        });
    }

    /**
     * Only the added column is reversed. Restoring NOT NULL would fail against
     * the very rows this migration exists to allow — an emailless driver, an
     * unclaimed vehicle — so the relaxations are deliberately one-way.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['id_number']);
            $table->dropColumn('id_number');
        });
    }
};
