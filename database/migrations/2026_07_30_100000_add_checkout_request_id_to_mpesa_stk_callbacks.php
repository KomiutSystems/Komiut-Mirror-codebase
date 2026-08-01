<?php

declare(strict_types=1);

use App\Models\MpesaStkCallback;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The passenger app polls STK payment status by CheckoutRequestID. That value
 * has only ever lived inside the `callback` JSON blob (the reconciler json_decodes
 * it), so a status lookup would table-scan and JSON-parse every row. Promote it
 * to an indexed column, and add `cancelled_at` for the "customer backed out
 * before entering their PIN" intent — local only, since Safaricom has no cancel
 * API, and the reconciler stays authoritative regardless of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mpesa_stk_callbacks', function (Blueprint $table): void {
            $table->string('checkout_request_id', 64)->nullable()->after('callback_nonce')->index();
            $table->timestamp('cancelled_at')->nullable()->after('processed_at');
        });

        $this->backfillCheckoutRequestIds();
    }

    public function down(): void
    {
        Schema::table('mpesa_stk_callbacks', function (Blueprint $table): void {
            $table->dropColumn(['checkout_request_id', 'cancelled_at']);
        });
    }

    /** Lift CheckoutRequestID out of the stored initiate-response JSON. */
    private function backfillCheckoutRequestIds(): void
    {
        MpesaStkCallback::query()
            ->whereNull('checkout_request_id')
            ->whereNotNull('callback')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $decoded = json_decode((string) $row->callback, true);
                    $id = is_array($decoded) ? ($decoded['CheckoutRequestID'] ?? null) : null;

                    if (is_string($id) && $id !== '') {
                        MpesaStkCallback::whereKey($row->id)->update(['checkout_request_id' => $id]);
                    }
                }
            });
    }
};
