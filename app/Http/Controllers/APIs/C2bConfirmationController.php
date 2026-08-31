<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Models\MpesaLog;
use App\Models\Vehicle;
use App\Services\Mpesa\C2bPaymentRecorder;
use App\Services\Mpesa\VehicleByShortCode;
use App\Services\Super\Money\PaymentReconciliationAlerter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * Safaricom C2B, on the per-till URL shape the fleet is actually registered
 * against: `{APP_URL}/api/confirmation/{mpesa_setting_id}`.
 *
 * WHY THIS SHAPE AND NOT A NEW ONE. This is not a design choice, it is a
 * compatibility contract. The legacy payment tier registers each till with
 * Safaricom itself — MpesaAPIController::mpesaRegisterTillUrls calls
 * `mpesa/c2b/v2/registerurl` with ConfirmationURL
 * `config('app.url')."/api/confirmation/".$mpesaSetting->id` — and 1,336,113
 * confirmations have been delivered to exactly that path. Migrating a till means
 * re-registering it with this host in place of the old one, which only works if
 * this host answers the same URL shape with the same body. Anything else would
 * need Safaricom-side coordination per till.
 *
 * THE {id} IS NOT A SHORTCODE, AND IT IS NOT OURS. It is the primary key of a row
 * in the LEGACY `komiut_payments` database. It is recorded (see the
 * add_mpesa_setting_id_to_mpesas migration) so that "which tills have moved" is a
 * GROUP BY rather than 178 questions to Safaricom, but it is deliberately NOT
 * used to attribute the payment: attribution is by BusinessShortCode, exactly as
 * every other C2B path here resolves it. Trusting a caller-supplied id to select
 * whose money this is would make the URL itself a way to redirect takings.
 *
 * ACK SEMANTICS, COPIED DELIBERATELY. Legacy answers with
 * `{"C2BPaymentConfirmationResult":"Success"}` under a text/xml content type, and
 * answers it BEFORE doing the work. Safaricom retries anything that is slow or
 * non-2xx, so the response is the receipt, not the outcome. This class keeps that
 * behaviour: it never returns an error to Safaricom for a payment it has already
 * accepted, because a retry storm is worse than a row we can repair. Failures are
 * logged and surfaced through the unmatched-payment path instead.
 */
class C2bConfirmationController extends Controller
{
    public function __construct(private readonly C2bPaymentRecorder $recorder) {}

    /**
     * Safaricom calls this first when a validation URL is registered. We do not
     * decline payments — the fleet takes fares from anyone — so this always
     * accepts. It exists because the registered pair must both answer; an
     * unanswered ValidationURL makes Safaricom fail the transaction outright.
     */
    public function validation(Request $request, string $id): Response
    {
        return $this->ack(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    public function confirmation(Request $request, string $id): Response
    {
        $fields = $request->all();

        // Logged BEFORE anything else, and outside the try: a payment we failed
        // to parse must still leave a trace with its raw body, because that trace
        // is the only way to reconstruct money that a later bug dropped. This is
        // the table that proves, during a till migration, that traffic has
        // actually started arriving here.
        try {
            MpesaLog::create([
                'trans_id' => (string) ($fields['TransID'] ?? ''),
                'log' => json_encode($fields),
                'ip_address' => $request->getClientIp(),
            ]);
        } catch (\Throwable $e) {
            Log::error('c2b confirmation: could not write MpesaLog', ['error' => $e->getMessage()]);
        }

        $fields['MpesaSettingId'] = ctype_digit($id) ? (int) $id : null;

        // $billRef is deliberately unused here — see resolveVehicle's docblock
        // for why this path has no BillRefNumber fallback.
        $result = $this->recorder->record($fields, function (string $shortCode, ?string $billRef) use ($fields) {
            $vehicle = $this->resolveVehicle($shortCode);

            if ($vehicle === null) {
                $this->reportUnmatched($fields);
            }

            return $vehicle;
        });

        if (! $result->ok) {
            // Still a Success ack — see the class docblock. The row is in
            // mpesa_logs and the error is in the application log.
            Log::error('c2b confirmation: recording failed', [
                'trans_id' => $fields['TransID'] ?? null,
                'setting_id' => $id,
                'error' => $result->error,
            ]);
        }

        return $this->ack(['C2BPaymentConfirmationResult' => 'Success']);
    }

    /**
     * Delegates to VehicleByShortCode, which is now the single definition of
     * this rule — the re-attribution repair reads it too, and two copies of the
     * rule that decides whose money a payment is would be free to drift apart.
     * All the reasoning (why withoutGlobalScopes, why a multi-match is refused,
     * why there is no BillRefNumber fallback) lives with it there.
     */
    private function resolveVehicle(string $shortCode): ?Vehicle
    {
        return VehicleByShortCode::resolve($shortCode);
    }

    /** @param array<string,mixed> $fields */
    private function reportUnmatched(array $fields): void
    {
        try {
            app(PaymentReconciliationAlerter::class)->record(
                Context::has('brand') ? (string) Context::get('brand') : null,
                (string) ($fields['TransID'] ?? ''),
                (float) ($fields['TransAmount'] ?? 0),
            );
        } catch (\Throwable $e) {
            Log::warning('c2b confirmation: unmatched payment, and reporting it failed', [
                'short_code' => $fields['BusinessShortCode'] ?? null,
                'trans_id' => $fields['TransID'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string,mixed> $body */
    private function ack(array $body): Response
    {
        // text/xml with a JSON body is what legacy sends and what Safaricom has
        // accepted 1.3M times. Matching it exactly removes one variable from the
        // migration.
        $response = new Response;
        $response->headers->set('Content-Type', 'text/xml; charset=utf-8');
        $response->setContent(json_encode($body));

        return $response;
    }
}
