<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The account number a passenger's money arrives under.
 *
 * WHY A SEPARATE CODE AND NOT THE USER ID. This value is typed by a passenger
 * into an M-Pesa paybill prompt and is sent to the bank as the AccountNo, so it
 * has to survive being read off a screen and keyed in by hand on a matatu. A
 * sequential id also enumerates: anyone could pay into the next passenger's
 * wallet, or work out how many passengers exist. App\Services\Wallet\WalletAccountNumber
 * generates it from an alphabet with no 0/O or 1/I/L, for the same reason.
 *
 * NULLABLE, and assigned on demand. Backfilling every wallet now would mint
 * codes for passengers who will never top up, and the column is only needed at
 * the moment a top-up is started.
 *
 * NOT SHOWN BY THE APP YET: the wallet contract in wallet_data.dart has no
 * field for it, so GET /wallet returns it additively (unknown keys are ignored
 * by the client) and displaying it to a passenger -- which manual paybill
 * top-ups need -- is a mobile change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            // Unique so two passengers can never share one; case is normalised
            // to upper by the generator, and lookups normalise the input.
            $table->string('account_no', 16)->nullable()->unique()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('account_no');
        });
    }
};
