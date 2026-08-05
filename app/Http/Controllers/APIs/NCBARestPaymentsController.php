<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Models\MpesaLog;
use App\Models\Vehicle;
use App\Services\Mpesa\C2bPaymentRecorder;
use App\Services\Super\Money\PaymentReconciliationAlerter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

class NCBARestPaymentsController extends Controller
{
    public function __construct(private readonly C2bPaymentRecorder $recorder) {}

    /**
     * Older confirmation path (no bank credential check). Vehicle resolution
     * special-cases NCBA's own aggregator shortcode (880100): a payment there is
     * billed against a vehicle's till_number (the BillRefNumber), not a
     * per-vehicle merchant_short_code — every other shortcode is looked up
     * directly by merchant_short_code.
     */
    public function restMpesaPayments(Request $request)
    {
        $fields = $request->all();

        $mpesaLog = new MpesaLog;
        $mpesaLog->log = json_encode($fields);
        $mpesaLog->ip_address = $request->getClientIp();
        $mpesaLog->trans_id = (string) ($fields['TransID'] ?? '');
        $mpesaLog->save();

        if ((float) ($fields['TransAmount'] ?? 0) <= 0) {
            return '{"ResultCode":1, "ResultDesc":"Invalid amount", "ThirdPartyTransID": 1}';
        }

        $result = $this->recorder->record($fields, function (string $shortCode, ?string $billRef) use ($fields) {
            $vehicle = $shortCode === '880100'
                ? Vehicle::where('till_number', $billRef)->first()
                : Vehicle::where('merchant_short_code', $shortCode)->first();

            if ($vehicle === null) {
                $this->reportUnmatchedPayment($fields);
            }

            return $vehicle;
        });

        return $result->ok
            ? '{"ResultCode":0, "ResultDesc":"sucessful validation", "ThirdPartyTransID": 0}'
            : '{"ResultCode":1, "ResultDesc":"Failed Transaction", "ThirdPartyTransID": 1}';
    }

    public function mpesaNewPayments(Request $request)
    {
        $soapResponse = $request->getContent();
        $xmlObject = simplexml_load_string($soapResponse);
        $jsonString = json_encode($xmlObject);
        $jsonObject = json_decode($jsonString);

        $response = json_decode($this->savePayments($jsonObject));

        return $response->ResultCode == 0 ? 'OK' : 'FAIL';
    }

    public function restMpesaNewPayments(Request $request)
    {
        return $this->savePayments($request);
    }

    /**
     * Verify the bank-issued credentials NCBA posts on the confirmation webhook.
     * Config-driven (never hard-coded) and constant-time; fails closed if unset.
     */
    private function ncbaAuthorised($username, $password): bool
    {
        $u = (string) config('services.ncba.username');
        $p = (string) config('services.ncba.password');

        return $u !== '' && $p !== ''
            && hash_equals($u, (string) $username)
            && hash_equals($p, (string) $password);
    }

    /**
     * The endpoint named in NCBA's own integration letter
     * (POST /api/rest/mpesa/confirmation_new). $request is a Request on the REST
     * path or a stdClass (decoded from XML) on the SOAP path — both are just
     * property bags here, so they are normalised to an array up front. Previously
     * this logged json_encode($request) directly: Symfony's Request only exposes
     * its data through protected ParameterBag properties, so that call silently
     * produced an empty audit record ({"attributes":{},"request":{},...}) for
     * every single confirmation — the raw payload was never actually captured.
     *
     * Vehicle resolution here is ALWAYS by merchant_short_code, deliberately not
     * mirroring restMpesaPayments' 880100 special-case — that is the existing,
     * tested contract for this endpoint (see NcbaWebhookAuthTest).
     */
    public function savePayments($request)
    {
        $fields = $request instanceof Request ? $request->all() : (array) $request;

        $mpesaLog = new MpesaLog;
        $mpesaLog->log = json_encode($fields);
        $mpesaLog->save();

        if (empty($fields['Username']) || empty($fields['Password'])) {
            return '{"ResultCode":1, "ResultDesc":"Username/Password Required"}';
        }

        if (! $this->ncbaAuthorised($fields['Username'], $fields['Password'])) {
            return '{"ResultCode":1, "ResultDesc":"Wrong Username/Password"}';
        }

        $mpesaLog->trans_id = (string) ($fields['TransID'] ?? '');
        $mpesaLog->save();

        if ((float) ($fields['TransAmount'] ?? 0) <= 0) {
            return '{"ResultCode":1, "ResultDesc":"Invalid amount"}';
        }

        // This endpoint's payload carries the payer under Mobile/Name rather than
        // MSISDN/FirstName+LastName — normalise into the recorder's canonical shape.
        $name = explode(' ', (string) ($fields['Name'] ?? $fields['name'] ?? ''));
        $normalised = $fields;
        $normalised['MSISDN'] = $fields['Mobile'] ?? '';
        $normalised['FirstName'] = $name[0] ?? '';
        $normalised['MiddleName'] = $name[1] ?? '';
        $normalised['LastName'] = $name[2] ?? '';

        $result = $this->recorder->record(
            $normalised,
            function (string $shortCode) use ($normalised) {
                $vehicle = Vehicle::where('merchant_short_code', $shortCode)->first();

                if ($vehicle === null) {
                    $this->reportUnmatchedPayment($normalised);
                }

                return $vehicle;
            }
        );

        return $result->ok
            ? '{"ResultCode":0, "ResultDesc":"sucessful validation", "ThirdPartyTransID": 0}'
            : '{"ResultCode":1, "ResultDesc":"Failed Transaction"}';
    }

    /**
     * A confirmation whose shortcode/till resolves to no vehicle is money we
     * received but can't attribute — the unmatched path the super console watches.
     *
     * @param  array<string,mixed>  $fields
     */
    private function reportUnmatchedPayment(array $fields): void
    {
        $brand = Context::has('brand')
            ? (string) Context::get('brand')
            : null;

        app(PaymentReconciliationAlerter::class)->record(
            $brand,
            (string) ($fields['TransID'] ?? ''),
            (float) ($fields['TransAmount'] ?? 0),
        );
    }
}
