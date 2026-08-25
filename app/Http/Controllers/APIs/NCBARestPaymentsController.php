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
    /**
     * NCBA's aggregator paybill. Every SACCO on the aggregator collects through
     * this one shortcode, so it identifies the BANK, never a bus — the vehicle
     * is carried in BillRefNumber as its till_number.
     */
    private const NCBA_AGGREGATOR_SHORTCODE = '880100';

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
            $vehicle = $this->resolveVehicle($shortCode, $billRef);

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
            function (string $shortCode, ?string $billRef) use ($normalised) {
                $vehicle = $this->resolveVehicle($shortCode, $billRef);

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
     * The ONE definition of "which bus does this confirmation belong to", shared
     * by both NCBA entry points.
     *
     * It used to be duplicated, and the two copies disagreed. restMpesaPayments
     * special-cased NCBA's aggregator shortcode 880100 — where the money is
     * billed against a vehicle's till_number carried in BillRefNumber, because
     * every SACCO on the aggregator shares that one shortcode — while
     * savePayments, which is the handler on the URL NCBA's own letter names,
     * resolved by merchant_short_code unconditionally. Production has 34 vehicles
     * carrying merchant_short_code 880100, so `->first()` returned one arbitrary
     * bus and EVERY aggregator payment was credited to it. Silently: because a
     * vehicle WAS found, the unmatched-payment alarm never fired.
     *
     * The multi-match guard is the general form of that bug. A shortcode that
     * matches more than one vehicle cannot be attributed — picking the first row
     * is a coin toss with someone's takings — so it is treated as unmatched and
     * surfaced, which is recoverable, instead of silently mis-credited, which is
     * not.
     */
    private function resolveVehicle(string $shortCode, ?string $billRef): ?Vehicle
    {
        $query = $shortCode === self::NCBA_AGGREGATOR_SHORTCODE
            ? Vehicle::where('till_number', $billRef)
            : Vehicle::where('merchant_short_code', $shortCode);

        // take(2): enough to know whether it is ambiguous, without loading a fleet.
        $matches = $query->take(2)->get();

        if ($matches->count() !== 1) {
            return null;
        }

        return $matches->first();
    }

    /**
     * A confirmation whose shortcode/till resolves to no vehicle — or to more
     * than one — is money we received but can't attribute. This is the unmatched
     * path the super console watches.
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
