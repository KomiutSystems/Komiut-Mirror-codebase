<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A wallet fare, in the fare ledger and in the day's takings.
 *
 * WHY A WALLET FARE IS A `transactions` ROW AT ALL. The crew's takings screen,
 * the owner reports and the SACCO dashboards are all built on `transactions`
 * joined to `summaries`. A fare paid from a wallet that wrote nothing there
 * would be invisible to every one of them -- the passenger would have paid, the
 * ride would have happened, and the bus's money for the day would be short with
 * nothing explaining why.
 *
 * WHY ITS OWN BUCKET, AND NOT `mpesa_amount`. GenerateVehicleSummaries keys
 * mpesa_* on `mpesa_id > 0` and cash_* on `cash_id > 0`; a wallet fare has
 * neither. It could have been counted as M-Pesa, and that would have been a lie
 * with consequences: the SACCO reconciles `mpesa_amount` against money that
 * actually landed in their till, and wallet money has NOT -- it is sitting in
 * the Komiut float waiting to be settled (App\Services\Wallet\WalletFloat).
 * Counting it as till money would make their bank reconciliation fail by
 * exactly the amount of every wallet ride.
 *
 * So `wallet_amount` / `wallet_txn` sit beside the other two. Anything summing
 * mpesa + cash is unaffected and stays correct; anything that wants total
 * earnings adds the third.
 *
 * `transactions.wallet_transaction_id` is what the bucket keys on -- deliberately
 * a real FK rather than "mpesa_id IS NULL AND cash_id IS NULL", because that
 * condition is also true of every unattributed payment the orphan sweep deals
 * with, and conflating the two would move other people's money into the wallet
 * column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('wallet_transaction_id')->nullable()->after('mpesa_id');

            // One fare per wallet movement. The debit and this row are written
            // in the same transaction, so a retry that reached the ledger
            // cannot write a second fare against it.
            $table->unique('wallet_transaction_id');
        });

        Schema::table('summaries', function (Blueprint $table) {
            // Defaults so every existing row reads zero rather than null: the
            // dashboards cast these and a null would render as blank where a
            // number belongs.
            $table->double('wallet_amount')->unsigned()->default(0)->after('cash_amount');
            $table->unsignedInteger('wallet_txn')->default(0)->after('cash_txn');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['wallet_transaction_id']);
            $table->dropColumn('wallet_transaction_id');
        });

        Schema::table('summaries', function (Blueprint $table) {
            $table->dropColumn(['wallet_amount', 'wallet_txn']);
        });
    }
};
