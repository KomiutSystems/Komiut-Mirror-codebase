<?php

declare(strict_types=1);

use App\Models\Sacco;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The letter to NCBA asking for the API push-notification service on a set of
 * tills, and the credentials that come back.
 *
 * PER SACCO, not per vehicle, and that is not a style choice:
 * `mpesa_payment_settings` holds ONE set of Daraja credentials per SACCO and
 * every vehicle differs only by its own till_number / merchant_short_code. The
 * letter matches — "NCBA Till No(s)" is plural against the single aggregator
 * paybill 880100 — so one request lists many vehicle tills and the credentials
 * that come back belong to the SACCO.
 *
 * The three credential columns are encrypted at rest with the same cast the
 * Daraja settings use, and are nullable because the letter is sent with
 * "Username: TBA" and filled in when the bank replies.
 *
 * `issued_tills` is the important one. NCBA replies with the till numbers it has
 * opened, and those are NOT applied to vehicles automatically — they land here
 * and a human applies them. KDY 599G is why: its `merchant_short_code` was
 * wrong for a month, its collections were invisible the whole time, and the
 * record looked perfectly healthy. A partner key that could write that field
 * directly is a partner key that can silently redirect a bus's takings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_till_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Sacco::class)->index();
            $table->string('brand')->index();
            $table->string('bank');                       // App\Enums\BankPartner

            // The letter, as data. The UI renders it; nothing here is a document.
            $table->date('letter_date')->nullable();
            $table->string('subject');                    // the RE: (____) blank
            $table->string('paybill')->default('880100'); // the aggregator paybill
            $table->json('till_numbers')->nullable();     // requested
            $table->json('buygoods_numbers')->nullable();
            $table->string('request_format')->default('json');   // json | xml
            $table->string('endpoint_url');
            $table->json('signatories')->nullable();      // names/titles; signed offline

            // Filled in when the bank replies. Encrypted — see the model.
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->text('secret_key')->nullable();

            // What the bank actually opened: [{plate, till}], staged for a human
            // to apply. Never written straight onto a vehicle.
            $table->json('issued_tills')->nullable();
            $table->timestamp('credentials_received_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignIdFor(User::class, 'applied_by')->nullable();

            $table->string('status')->default('draft');   // draft|sent|credentials_received|applied
            $table->foreignIdFor(User::class)->nullable()->comment('who drafted it');
            $table->timestamps();

            $table->index(['brand', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_till_requests');
    }
};
