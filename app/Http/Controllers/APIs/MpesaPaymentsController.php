<?php

namespace App\Http\Controllers\APIs;

use App\Brands\Brand;
use App\Enums\BookingCancellationReason;
use App\Enums\PaymentMethod;
use App\Events\BookingCancelled;
use App\Http\Controllers\Controller;
use App\Jobs\SendFCMJob;
use App\Models\Booking;
use App\Models\MpesaBookingCallback;
use App\Models\MpesaQrcodePayment;
use App\Models\MpesaPaymentSetting;
use App\Models\MpesaStkCallback;
use App\Models\QrcodePayment;
use App\Models\Vehicle;
use App\Services\Booking\BookingCancellation;
use App\Services\CarbonCredits\CarbonCreditService;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Mpesa\MpesaCredentialResolver;
use App\Services\Payments\QrTokenService;
use App\Services\Super\Money\PaymentReconciliationAlerter;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class MpesaPaymentsController extends Controller
{
    protected $BusinessShortCode;

    protected $passkey;

    protected $consumer_key;

    protected $consumer_secret;

    protected $till;

    protected $paymentMode;

    protected $url = '';

    /**
     * Daraja's timestamp format.
     *
     * YmdHis — NOT YmdHms. `m` is the MONTH, so the old format put the month
     * where the minutes belong: 14:37 on a day in August serialised as 14:08.
     */
    private function stkTimestamp(): string
    {
        return Carbon::rawParse('now')->format('YmdHis');
    }

    /**
     * The STK password: base64(shortcode + passkey + timestamp).
     *
     * The timestamp is a PARAMETER, deliberately. This used to read the clock
     * itself while the caller read it a second time for the `Timestamp` field
     * sent alongside — two separate now() calls microseconds apart. Cross a
     * second boundary between them and the password encodes a timestamp that is
     * not the one Daraja was given, so Daraja rejects the push with "Invalid
     * Password". Intermittent, timing-dependent, and indistinguishable from a
     * bad passkey. Threading ONE timestamp through both makes it impossible.
     *
     * DarajaClient::stkQuery has always done it this way; this brings the push
     * side into line with it.
     */
    public function lipaNaMpesaPassword(string $timestamp)
    {
        return base64_encode(intval($this->BusinessShortCode).$this->passkey.$timestamp);
    }

    public function customerMpesaSTKPush(Request $request)
    {
        // `amount` is accepted for backward compatibility but IGNORED — the charge
        // is the booking's server-set fare, never a client-supplied number.
        $validator = Validator::make($request->all(), [
            'phone' => 'string|min:9|max:12|required',
            'amount' => 'integer|nullable',
            'booking_id' => 'required|min:1|integer',
        ]);
        // Accept +254…, 254…, 07…, or 7… — Daraja needs the 2547XXXXXXXX form.
        // Falls back to the old best-effort only if the number is unrecognisable,
        // in which case the validator/Daraja rejects it downstream.
        $phone = Phone::msisdn($request->phone) ?? '254'.intval($request->phone);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }
        $booking = Booking::with('queue.vehicle', 'queue.queue_status')->where('id', $request->booking_id)->first();

        if ($booking === null) {
            // 404, not 401. This endpoint's failures all used to be 401, and the
            // app reads 401 as "your session ended" -- so a passenger whose push
            // could not start was also signed out, and the access log shows the
            // handset re-authenticating one second after each failure.
            return response()->json(['error' => 'Invalid booking id'], 404);
        }

        // Whose booking is this? Asked BEFORE the payment settings are resolved,
        // so the answer cannot depend on how the vehicle happens to be
        // configured. Asked after, an unconfigured till returned 401 "invalid
        // keys" to a stranger poking at someone else's booking -- refusing by
        // accident, and telling them about the vehicle instead of about their
        // own standing.
        //
        // Authenticating the route stops the internet firing PIN prompts at
        // strangers; this stops one PASSENGER doing it to another. booking_id is
        // sequential, so without it any signed-in account could walk the ids and
        // prompt every payer on the platform.
        //
        // Staff pass: a conductor takes payment for a passenger standing in
        // front of them, and the booking they created carries the passenger as
        // user_id. Same rule and wording as BookingsAPIController's cancel path.
        $isStaff = auth()->user()->can('Edit Passengers');
        $isOwner = (int) $booking->user_id === (int) auth()->id()
            || (int) $booking->created_by === (int) auth()->id();

        if (! $isStaff && ! $isOwner) {
            return response()->json(['error' => 'This booking is not yours.'], 403);
        }

        // Already settled. Re-pushing would charge twice for one seat.
        if ((bool) $booking->paid) {
            return response()->json(['error' => 'This booking is already paid.'], 422);
        }

        // Cancelled, or on a trip that has ended: there is no ride to pay for,
        // and money that lands on a dead booking has nobody left to no-show it.
        if (! (bool) $booking->status) {
            return response()->json(['error' => 'This booking is no longer active. Please book again.'], 422);
        }
        if (BookingCancellation::isTripOver($booking->queue)) {
            return response()->json(['error' => 'This trip has ended. Please book another.'], 422);
        }

        $vehicle = $booking->queue?->vehicle;
        if ($vehicle === null) {
            return response()->json(['error' => 'This booking has no vehicle to pay.'], 422);
        }

        if (($refused = $this->configureFor($vehicle)) !== null) {
            return $refused;
        }

        // Charge the fare the server set on the booking, not the client's
        // number. bookings.amount is the whole fare -- per-seat x seats.
        $chargeAmount = (int) round((float) $booking->amount);
        if ($chargeAmount <= 0) {
            return response()->json(['error' => 'This booking has no fare to charge.'], 422);
        }

        $token = $this->generateAccessToken();
        if ($token == '') {
            return $this->darajaUnavailable();
        }

        // Unguessable per-payment nonce. The callback is keyed by this, never
        // by the booking id, so a forged callback cannot target a booking.
        $callbackNonce = bin2hex(random_bytes(32));

        // One clock read, used for BOTH the password and the Timestamp field.
        $stkTimestamp = $this->stkTimestamp();

        $curl_post_data = [
            'BusinessShortCode' => intval($this->BusinessShortCode),
            'Password' => $this->lipaNaMpesaPassword($stkTimestamp),
            'Timestamp' => $stkTimestamp,
            'TransactionType' => $this->paymentMode, // 'CustomerPayBillOnline' : 'CustomerBuyGoodsOnline',
            'Amount' => $chargeAmount,
            'PartyA' => intval($phone),
            'PartyB' => intval($this->paymentMode == 'CustomerPayBillOnline' ? $this->BusinessShortCode : $this->till),
            'PhoneNumber' => intval($phone),
            'CallBackURL' => url('/').'/api/'.app(Brand::class)->key.'/stk/push/response/'.$callbackNonce,
            'AccountReference' => ''.$request->booking_id,
            'TransactionDesc' => 'Online Booking',
        ];
        // NEVER log $curl_post_data whole. `Password` is
        // base64(shortcode + passkey + timestamp), and this same array carries
        // BusinessShortCode and Timestamp in clear — so a full dump lets anyone
        // with log access decode it, strip both known ends, and recover the raw
        // passkey. `PhoneNumber`/`PartyA` are customer PII for the same reason.
        \Log::info('stk push initiated', [
            'shortcode' => $curl_post_data['BusinessShortCode'],
            'amount' => $curl_post_data['Amount'],
            'reference' => $curl_post_data['AccountReference'],
            'nonce' => substr($callbackNonce, 0, 8).'…',
        ]);
        $response = $this->pushToDaraja($curl_post_data, $token);

        $mpesaStkCallback = new MpesaStkCallback;
        $mpesaStkCallback->booking_id = $request->booking_id;
        $mpesaStkCallback->callback_nonce = $callbackNonce;
        // Indexed so the app can poll status/cancel by CheckoutRequestID.
        $mpesaStkCallback->checkout_request_id = $response['CheckoutRequestID'] ?? null;
        $mpesaStkCallback->callback = json_encode($response);
        $mpesaStkCallback->save();

        return $this->pushOutcome($response);
    }

    /**
     * QR STK push (pay a scanned matatu)
     *
     * Charges the passenger-entered amount to the vehicle's till via M-Pesa STK.
     * Prefer `qr_token` from a scanned QR (tamper-proof); `vehicle_id` is accepted
     * for legacy callers. The amount is client-supplied because fares vary. A
     * lost/delayed callback is recovered by the `payments:reconcile` poller.
     *
     * @group QR-code fare payment
     *
     * @bodyParam qr_token string The signed token from a scanned QR (preferred). Example: eyJ2ZWhpY2xlX2lkIjoxfQ.f3a9...
     * @bodyParam vehicle_id integer required_without:qr_token The vehicle id (legacy). Example: 1
     * @bodyParam amount integer required Amount in KES the passenger pays. Example: 120
     * @bodyParam phone string required 10-digit M-Pesa phone. Example: 0700111222
     */
    public function customerQRCodeSTKPush(Request $request)
    {
        // Prefer a signed QR token: the vehicle identity is tamper-proof, resolved
        // from the HMAC-signed token rather than a client-supplied id.
        if ($request->filled('qr_token')) {
            $claims = app(QrTokenService::class)->validate($request->qr_token);
            if (! $claims || empty($claims['vehicle_id'])) {
                return response()->json(['error' => 'Invalid or tampered QR code'], 422);
            }
            $request->merge(['vehicle_id' => (int) $claims['vehicle_id']]);
        }

        $validator = Validator::make($request->all(), [
            'vehicle_id' => 'required|integer|exists:vehicles,id',
            'amount' => 'integer|required|min:1',
            'phone' => 'required|string|min:9|max:13',
            'seat_id' => 'nullable|integer|exists:seat_arrangements,id',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }
        // Accept +254…, 254…, 07…, or 7… — Daraja needs the 2547XXXXXXXX form.
        // Falls back to the old best-effort only if the number is unrecognisable,
        // in which case the validator/Daraja rejects it downstream.
        $phone = Phone::msisdn($request->phone) ?? '254'.intval($request->phone);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }
        $vehicle = Vehicle::find($request->vehicle_id);
        if ($vehicle === null) {
            return response()->json(['error' => 'Invalid Vehicle'], 404);
        }

        if (($refused = $this->configureFor($vehicle)) !== null) {
            return $refused;
        }

        $token = $this->generateAccessToken();
        if ($token == '') {
            return $this->darajaUnavailable();
        }
        $qrcodePayment = new QrcodePayment;
        $qrcodePayment->vehicle_id = $vehicle->id;
        // Owner is the authenticated caller — this is what StkStatusController's
        // ownership check matches on, so without it the passenger's status poll
        // can never find its own QR payment and the payment reads as FAILED even
        // when it succeeds. The route is authenticated (auth:sanctum), so
        // auth()->id() is the passenger; fall back to the (legacy) client-supplied
        // user_id only for any still-unauthenticated caller.
        $qrcodePayment->user_id = auth()->id() ?? $request->user_id;
        $qrcodePayment->seat_arrangement_id = $request->seat_id;
        $qrcodePayment->amount = $request->amount;
        if ($qrcodePayment->save()) {
            // Unguessable per-payment nonce - see customerMpesaSTKPush.
            $callbackNonce = bin2hex(random_bytes(32));

            // One clock read, used for BOTH the password and the Timestamp field.
            $stkTimestamp = $this->stkTimestamp();

            $curl_post_data = [
                'BusinessShortCode' => intval($this->BusinessShortCode),
                'Password' => $this->lipaNaMpesaPassword($stkTimestamp),
                'Timestamp' => $stkTimestamp,
                'TransactionType' => $this->paymentMode, // 'CustomerPayBillOnline' : 'CustomerBuyGoodsOnline',
                'Amount' => intval($request->amount),
                'PartyA' => intval($phone),
                'PartyB' => intval($this->paymentMode == 'CustomerPayBillOnline' ? $this->BusinessShortCode : $this->till),
                'PhoneNumber' => intval($phone),
                'CallBackURL' => url('/').'/api/'.app(Brand::class)->key.'/stk/push/response/'.$callbackNonce,
                'AccountReference' => ''.$qrcodePayment->id,
                'TransactionDesc' => 'Online Booking',
            ];
            $response = $this->pushToDaraja($curl_post_data, $token);

            $mpesaStkCallback = new MpesaStkCallback;
            $mpesaStkCallback->qrcode_payment_id = $qrcodePayment->id;
            $mpesaStkCallback->callback_nonce = $callbackNonce;
            $mpesaStkCallback->checkout_request_id = $response['CheckoutRequestID'] ?? null;
            $mpesaStkCallback->callback = json_encode($response);
            $mpesaStkCallback->save();

            return $this->pushOutcome($response);
        } else {
            return response()->json(['error' => 'Unable to proceed with payments'], 500);
        }
    }

    /**
     * Point this request at the merchant a vehicle's payments run on.
     *
     * Returns the refusal to send when the bus cannot take an STK payment, or
     * null when everything needed is in place. 422, never 401: the bus not being
     * set up is a fact about the bus, and the app reads 401 as a sign-out.
     *
     * The lookup is MpesaCredentialResolver's, which reads the settings row
     * without the tenant scope. Read through the relations -- as both push
     * methods did for a year -- a PASSENGER caller got NULL for every vehicle on
     * the platform, because SaccoScope fails closed for anyone without a SACCO.
     * So every STK push from the app answered "No payments found for this
     * sacco", while the same lookup from an unauthenticated shell found the row
     * instantly and made the credentials look healthy. See the resolver for why
     * unscoped is the right reading here.
     */
    /**
     * A payment that landed on a booking whose trip is already over.
     *
     * Two shapes. The booking is still live but its queue is Completed or
     * Cancelled -- possible only through a path the trip-over settlement did
     * not cover -- and it is cancelled and refunded through the same write
     * every other not-boarded booking gets. Or the booking was ALREADY
     * cancelled (the trip-over sweep found it unpaid and had nothing to give
     * back) and the money has arrived since: it is refunded now, on the same
     * idempotent ledger key, and the passenger is told.
     *
     * Returns whether this happened, so the caller can skip telling the crew
     * about a fare on a trip they have finished.
     */
    private function settledBecauseTripIsOver(Booking $booking): bool
    {
        $booking->loadMissing('queue.queue_status');

        if ((bool) $booking->status && ! BookingCancellation::isTripOver($booking->queue)) {
            return false;
        }

        Log::warning('stk callback: payment landed after the trip ended', [
            'booking_id' => (int) $booking->id,
            'queue_id' => $booking->queue_id,
            'was_live' => (bool) $booking->status,
        ]);

        if ((bool) $booking->status) {
            app(BookingCancellation::class)->notBoarded($booking, BookingCancellationReason::TripOver);

            return true;
        }

        try {
            $refund = app(LoyaltyService::class)->refundForBooking($booking->fresh());
        } catch (Throwable $e) {
            report($e);

            return true;
        }

        if ($refund !== null) {
            BookingCancelled::dispatch($booking->fresh(), BookingCancellationReason::TripOver, (float) $refund->value);
        }

        return true;
    }

    private function configureFor(Vehicle $vehicle): ?JsonResponse
    {
        $setting = MpesaCredentialResolver::settingFor($vehicle);

        if ($setting === null) {
            return response()->json(['error' => 'This vehicle is not set up for M-Pesa payments yet.'], 422);
        }

        if (
            ! $setting->business_short_code || ! $setting->pass_key
            || ! $setting->consumer_key || ! $setting->consumer_secret
        ) {
            return response()->json(['error' => 'This vehicle is not set up for M-Pesa payments yet.'], 422);
        }

        $this->BusinessShortCode = $setting->business_short_code;
        $this->passkey = $setting->pass_key;
        $this->consumer_key = $setting->consumer_key;
        $this->consumer_secret = $setting->consumer_secret;
        $this->till = $vehicle->till_number;
        $this->paymentMode = $setting->payment_mode;
        $this->url = $setting->is_live ? 'https://api' : 'https://sandbox';

        return null;
    }

    /**
     * Daraja's answer, with a status that says whether a prompt is on its way.
     *
     * The body is Daraja's own, as both push methods have always returned it --
     * the app reads CheckoutRequestID from it to poll. What changes is the
     * status: a refusal ("Bad Request - Invalid PhoneNumber", an expired token)
     * used to come back 200 with an errorMessage inside, indistinguishable from
     * success to anything not parsing Safaricom's shape.
     *
     * @param  array<string, mixed>|null  $response
     */
    private function pushOutcome(?array $response): JsonResponse
    {
        if ($response === null) {
            return $this->darajaUnavailable();
        }

        $accepted = isset($response['CheckoutRequestID']) && (string) ($response['ResponseCode'] ?? '0') === '0';

        return response()->json($response, $accepted ? 200 : 502);
    }

    /** Safaricom did not hand us a token: their problem or our credentials, either way not the passenger's session. */
    private function darajaUnavailable(): JsonResponse
    {
        return response()->json(['error' => 'M-Pesa is not responding. Try again in a moment.'], 503);
    }

    /**
     * POST an STK request to Daraja and return its decoded answer, as the two
     * push methods always have -- but over the Http client rather than raw curl,
     * so a test can fake Safaricom and the happy path is finally exercised.
     *
     * A non-2xx or a non-JSON body is logged at WARNING. It was Log::info, and
     * production runs at `warning`, so the one line that would have said why a
     * push never reached a handset was dropped on the floor.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function pushToDaraja(array $payload, string $token): ?array
    {
        try {
            $res = Http::withToken($token)
                ->timeout(20)
                ->acceptJson()
                ->post($this->url.'.safaricom.co.ke/mpesa/stkpush/v1/processrequest', $payload);
        } catch (Throwable $e) {
            Log::warning('stk push: daraja unreachable', ['reference' => $payload['AccountReference'] ?? null, 'error' => $e->getMessage()]);

            return null;
        }

        $response = $res->json();

        if (! $res->successful() || ! is_array($response)) {
            // Daraja's error body names the problem ("Invalid Access Token",
            // "Bad Request - Invalid PhoneNumber") and carries no secret.
            Log::warning('stk push: daraja refused', [
                'status' => $res->status(),
                'reference' => $payload['AccountReference'] ?? null,
                'body' => mb_substr((string) $res->body(), 0, 500),
            ]);
        }

        return is_array($response) ? $response : null;
    }

    public function generateAccessToken()
    {
        try {
            $res = Http::withBasicAuth((string) $this->consumer_key, (string) $this->consumer_secret)
                ->timeout(15)
                ->acceptJson()
                ->get($this->url.'.safaricom.co.ke/oauth/v1/generate', ['grant_type' => 'client_credentials']);
        } catch (Throwable $e) {
            Log::warning('daraja token request failed', ['shortcode' => $this->BusinessShortCode, 'error' => $e->getMessage()]);

            return '';
        }

        if (! $res->ok() || ! is_string($res->json('access_token'))) {
            // Was Log::info, which production drops. The status is the whole
            // diagnosis -- 400 is Daraja for "these are not my credentials".
            Log::warning('daraja token request failed', [
                'shortcode' => $this->BusinessShortCode,
                'status' => $res->status(),
                'body' => mb_substr((string) $res->body(), 0, 300),
            ]);

            return '';
        }

        return $res->json('access_token');
    }

    /**
     * J-son Response to M-pesa API feedback - Success or Failure
     */
    public function createValidationResponse($result_code, $result_description)
    {
        $result = json_encode(['ResultCode' => $result_code, 'ResultDesc' => $result_description]);
        $response = new Response;
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->setContent($result);

        return $response;
    }

    /**
     *  M-pesa Validation Method
     * Safaricom will only call your validation if you have requested by writing an official letter to them
     */
    public function mpesaValidation(Request $request)
    {
        $result_code = '0';
        $result_description = 'Accepted validation request.';

        return $this->createValidationResponse($result_code, $result_description);
    }

    /**
     * Legacy callback path: `stk/push/response?booking_id=<id>`.
     *
     * This used to mark bookings paid from a guessable, client-supplied id on
     * an unauthenticated route. It now records the attempt and does nothing
     * else. Any hit here after in-flight pushes have drained is an attack.
     */
    public function stkResponseLegacy(Request $request)
    {
        Log::warning('Legacy STK callback path hit - no payment applied', [
            'ip' => $request->ip(),
            'booking_id' => $request->input('booking_id'),
            'qrcode_payment_id' => $request->input('qrcode_payment_id'),
        ]);

        return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Endpoint retired'], 410);
    }

    /**
     * Daraja STK callback, keyed by an unguessable per-payment nonce.
     *
     * The booking / QR payment is resolved from the STORED push record, never
     * from request input, so a forged callback cannot target an arbitrary
     * booking. `processed_at` makes Daraja's retries idempotent.
     */
    public function stkResponse(Request $request, string $brand, string $nonce)
    {
        // $brand is the leading `{brand}` route segment; the brand DB is already
        // active (ResolveBrandFromRoute ran). It is accepted here only so the
        // nonce binds to the correct method argument.
        $stkRecord = MpesaStkCallback::where('callback_nonce', $nonce)->first();

        if ($stkRecord === null) {
            Log::warning('STK callback with unknown nonce', [
                'ip' => $request->ip(),
            ]);

            // Deliberately opaque: don't confirm whether a nonce exists.
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Rejected'], 404);
        }

        if ($stkRecord->isProcessed()) {
            // Replay - already applied. Ack so Daraja stops retrying.
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Already processed']);
        }

        $content = json_decode($request->getContent());
        if ($content->Body->stkCallback->ResultCode == 0) {
            // \Log::info(json_encode($content->Body->stkCallback->ResultDesc));
            // \Log::info(json_encode($content->Body->stkCallback->CallbackMetadata);
            $items = $content->Body->stkCallback->CallbackMetadata->Item;
            $amount = 0.0;
            $transid = '';
            $transdate = '';
            $phone = '';
            foreach ($items as $item) {
                $name = (string) $item->Name;
                if ($name != 'Balance') {
                    $amount = $name == 'Amount' ? (float) $item->Value : $amount;
                    $transid = $name == 'MpesaReceiptNumber' ? (string) $item->Value : $transid;
                    $transdate = $name == 'TransactionDate' ? ''.substr((string) $item->Value, 0, 4).'-'.substr((string) $item->Value, 4, 2).'-'.substr((string) $item->Value, 6, 2).' '.substr((string) $item->Value, 8, 2).':'.substr((string) $item->Value, 10, 2).':'.substr((string) $item->Value, 12, 2) : $transdate;
                    $phone = $name == 'PhoneNumber' ? (string) $item->Value : $phone;
                }
            }
            // Identity comes from the stored push record, NOT from the request.
            $bookingId = $stkRecord->booking_id;
            $qrcodePaymentId = $stkRecord->qrcode_payment_id;

            if ($bookingId > 0) {
                $bookings = Booking::find($bookingId);

                if ($bookings === null) {
                    Log::error('STK callback for missing booking', ['booking_id' => $bookingId]);

                    // Money received that can't be matched to a booking → super console.
                    app(PaymentReconciliationAlerter::class)
                        ->record($brand, (string) $bookingId, (float) $amount);

                    return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Unknown booking'], 404);
                }

                $bookings->paid = true;
                // The rail that SETTLED it, not the one the app intended at
                // reservation. payment_method was stamped from the reserve
                // request and never touched again, so a booking reserved with
                // "loyalty_points" in mind and then paid by STK read as a points
                // ride on every list -- and a points ride collects no revenue.
                $bookings->payment_method = PaymentMethod::Mpesa;
                $bookings->save();

                $mpesaBookingCallback = new MpesaBookingCallback;
                $mpesaBookingCallback->transid = $transid;
                $mpesaBookingCallback->phone = $phone;
                $mpesaBookingCallback->transdate = Carbon::parse($transdate);
                $mpesaBookingCallback->booking_id = $bookingId;
                $mpesaBookingCallback->amount = $amount;
                $mpesaBookingCallback->callback = json_encode($content);
                $mpesaBookingCallback->save();

                // The money is real either way -- it is on the till -- but the
                // ride may not be: the passenger opened the PIN prompt while the
                // trip was live and the crew ended it before Safaricom answered.
                // A paid booking on a dead trip has nobody left to no-show it,
                // so it is settled here, the moment the money lands -- and the
                // crew is not told about a fare on a trip they have finished.
                if (! $this->settledBecauseTripIsOver($bookings)) {
                    $this->paymentsNotification($bookingId);
                }
            } else {
                $qrcodePayment = QrcodePayment::find($qrcodePaymentId);

                if ($qrcodePayment === null) {
                    Log::error('STK callback for missing qrcode payment', ['qrcode_payment_id' => $qrcodePaymentId]);

                    // Money received that can't be matched to a QR payment → super console.
                    app(PaymentReconciliationAlerter::class)
                        ->record($brand, (string) $qrcodePaymentId, (float) $amount);

                    return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Unknown payment'], 404);
                }

                $qrcodePayment->status = true;
                $qrcodePayment->save();

                $mpesaQrcodePayment = new MpesaQrcodePayment;
                $mpesaQrcodePayment->transid = $transid;
                $mpesaQrcodePayment->phone = $phone;
                $mpesaQrcodePayment->transdate = Carbon::parse($transdate);
                $mpesaQrcodePayment->qrcode_payment_id = $qrcodePaymentId;
                $mpesaQrcodePayment->amount = $amount;
                $mpesaQrcodePayment->callback = json_encode($content);
                $mpesaQrcodePayment->save();
                $this->awardQrRewards($qrcodePayment, $phone, (float) $amount);
                // $this->paymentsNotification($qrcodePaymentId);
            }
        }

        // Mark applied whatever the ResultCode, so a failed attempt's nonce
        // cannot be reused to submit a forged success later.
        $stkRecord->processed_at = now();
        $stkRecord->save();
    }

    /**
     * Credit BOTH reward schemes for a QR-code fare.
     *
     * A QR scan is an in-app payment, which is the whole basis on which rewards
     * are earned: a direct till payment needs no app and proves no app use, so it
     * earns nothing (see C2bPaymentRecorder). The two rails that do earn are an
     * STK push against a booking — handled by the BookingPaid listeners — and
     * this one.
     *
     * Both schemes were blind to it, for the same structural reason: each keyed
     * earning on a Booking, and a QR fare writes a QrcodePayment instead. For
     * loyalty that was a regression, since the legacy earner did credit QR
     * payments (GenerateUserPoints looped MpesaQrcodePayment rows).
     *
     * SACCO points and carbon credits are separate schemes and both are due:
     * loyalty is the SACCO's own, spendable on its buses; carbon credits are the
     * platform's, earned across every SACCO and brand. One fare, two ledgers.
     *
     * The payer is usually already known: QrcodePayment carries user_id, because
     * a passenger scans while signed in. The callback's phone number is the
     * fallback for rows where it is null.
     *
     * Loaded WITHOUT GLOBAL SCOPES — this runs in an unauthenticated webhook, and
     * a scoped lookup would resolve against no user and hand back nothing,
     * silently costing the credit.
     *
     * The two credits are guarded SEPARATELY on purpose: they are independent
     * ledgers, and a failure in one must not cost the passenger the other.
     * Neither may turn a completed payment into an error Safaricom will retry.
     */
    private function awardQrRewards(QrcodePayment $qrcodePayment, ?string $phone, float $amount): void
    {
        $loyalty = app(LoyaltyService::class);

        $vehicle = Vehicle::withoutGlobalScopes()->find($qrcodePayment->vehicle_id);

        $userId = $qrcodePayment->user_id !== null
            ? (int) $qrcodePayment->user_id
            : $loyalty->passengerIdForPhone($phone);

        if ($userId === null) {
            return; // nobody to credit
        }

        // The SACCO's own points, spendable on its buses.
        try {
            if ($vehicle !== null && $vehicle->sacco_id !== null) {
                $loyalty->earnForFare(
                    userId: $userId,
                    saccoId: (int) $vehicle->sacco_id,
                    amount: $amount,
                    sourceType: 'qrcode_payment',
                    sourceId: (int) $qrcodePayment->id,
                );
            }
        } catch (Throwable $e) {
            Log::error('loyalty earn failed for qr payment', [
                'qrcode_payment_id' => $qrcodePayment->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }

        // The platform's carbon credits — no SACCO needed, the passenger holds
        // one balance across every SACCO and brand.
        try {
            app(CarbonCreditService::class)->earnForFare(
                userId: $userId,
                amount: $amount,
                sourceType: 'qrcode_payment',
                sourceId: (int) $qrcodePayment->id,
            );
        } catch (Throwable $e) {
            Log::error('carbon credit earn failed for qr payment', [
                'qrcode_payment_id' => $qrcodePayment->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * M-pesa Transaction confirmation method, we save the transaction in our databases
     */
    public function mpesaConfirmation(Request $request)
    {
        Log::info($request->getContent());
        $content = json_decode($request->getContent());

        $transid = $content->TransID;
        $msisdn = $content->MSISDN;
        $transamount = $content->TransAmount;
        $transtime = Carbon::parse($content->TransTime);
        $firstname = isset($content->FirstName) ? $content->FirstName : '';
        $middlename = isset($content->MiddleName) ? $content->MiddleName : '';
        $lastname = isset($content->LastName) ? $content->LastName : '';
        $thirdpartytransid = isset($content->ThirdPartyTransID) ? $content->ThirdPartyTransID : '';
        $orgaccountbalance = isset($content->OrgAccountBalance) ? $content->OrgAccountBalance : '';
        $invoicenumber = isset($content->InvoiceNumber) ? $content->InvoiceNumber : '';
        // should come with the id of the queue
        $billreference = isset($content->BillRefNumber) ? $content->BillRefNumber : '';
        $business_short_code = isset($content->BusinessShortCode) ? $content->BusinessShortCode : '';
        $transtype = isset($content->TransactionType) ? $content->TransactionType : '';

        // save to db
        if (\DB::table('mpesas')->where('TransID', $transid)->count() == 0) {
            $id = \DB::table('mpesas')->insertGetId([
                'user_id' => 0,
                'TransID' => $transid,
                'MSISDN' => $msisdn,
                'TransAmount' => $transamount,
                'TransTime' => $transtime,
                'FirstName' => $firstname,
                'LastName' => $lastname,
                'MiddleName' => $middlename,
                'BusinessShortCode' => $business_short_code,
                'isUsed' => 0,
                'TransactionType' => $transtype,
                'ThirdPartyTransID' => $thirdpartytransid,
                'InvoiceNumber' => $invoicenumber,
                'BillRefNumber' => $billreference,
                'created_at' => \Carbon\Carbon::now(),
            ]);
            if ($id > 0) {
                $car = \DB::table('buses')->where('merchant_short_code', $billreference)->first();
                $car_id = $car != null ? $car->id : 0;
                \DB::table('transactions')->insert(['status' => 1, 'amount' => $transamount, 'mpesa_id' => $id, 'cash_id' => 0, 'bank_id' => 0, 'approve_by' => 0, 'car_id' => $car_id, 'created_at' => $transtime]);
                \DB::table('passengers')->where('id', $billreference)->update(['status' => 1]);
            }
        }

        $response = new Response;
        $response->headers->set('Content-Type', 'text/xml; charset=utf-8');
        $response->setContent(json_encode(['C2BPaymentConfirmationResult' => 'Success']));

        return $response;
    }

    // REMOVED: mpesaRegisterUrls().
    //
    // It called Daraja's c2b/v1/registerurl with three hard-coded values:
    //
    //   ShortCode       '174379'  <- Safaricom's public SANDBOX shortcode
    //   ConfirmationURL https://komiut.co.ke/api/transaction/confirmation
    //   ValidationURL   https://komiut.co.ke/api/validation
    //
    // Both URLs point at the OLD system (komiut.co.ke, a live host in
    // ap-northeast-3), not at this one. The method was never routed, so it
    // never ran — but calling it from tinker would have re-pointed C2B
    // callbacks for that shortcode away from this deployment, and it ignored
    // the per-SACCO shortcodes this system actually uses.
    //
    // Registering callback URLs re-routes real money and must not be a
    // hard-coded method with no arguments. When this system needs to own its
    // own C2B registration, add it deliberately: shortcode supplied by the
    // caller, URLs derived from this deployment's own config, and the whole
    // thing behind an explicit confirmation.

    public function paymentsNotification($booking_id)
    {
        $title = 'Payments Received';
        // $message = '';
        $user = Booking::with('to', 'from', 'queue.route.from', 'queue.route.to', 'user.firebase_tokens')->where('id', $booking_id)->first();
        if ($user->user->firebase_tokens->count() > 0) {
            $from = $user->queue->route->from->name;
            $to = $user->queue->route->to->name;
            if ($user->from != null) {
                $from = $user->from->name;
            }
            if ($user->to != null) {
                $to = $user->to->name;
            }

            $message = 'KSH '.number_format($user->amount, 2).' received for '.$from.' to '.$to;
            $tokens = $user->user->firebase_tokens->pluck('firebase_token');
            // $SERVER_API_KEY = 'AAAA72hVmKE:APA91bH7XEOwYftT006HjbaJFQB__VxB6Wc9funpAge8DRBxAbdSxta-ALRaup2_rXfkduwkGxO5VVnSa2h-zu86fh7R1PbT-NsbN3FoL2wAjE8W6TTiI6SYuQbk8zD1n55bN0tCKDPe';

            foreach ($tokens as $token) {
                dispatch(new SendFCMJob($token, $title, $message, 'payments', $booking_id));
            }
            /*$data = [
                "registration_ids" => $tokens,
                //"to" => "$token",
                "notification" => [
                    "title" => "$title",
                    "body" => "$message",
                    "sound" => "default",
                    //"badge" => "1",
                ],
                "data" => [
                    "booking_id" => $booking_id,
                    "queue_type" => $user->tripId == 0 ? 0 : 1
                ],
                "priority" => 10
            ];
            $dataString = json_encode($data);

            $headers = [
                'Authorization: key=' . $SERVER_API_KEY,
                'Content-Type: application/json',
            ];

            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $dataString);

            $response = curl_exec($ch);
            return $response;
            //dd($response);*/
        } else {
            return 'User not found';
        }
    }

    public function qrcodePaymentsNotification($qrcode_payment_id)
    {
        $title = 'Payments Received';
        // $message = '';
        $user = QrcodePayment::with('vehicle.sacco', 'user.firebase_tokens')->where('id', $qrcode_payment_id)->first();
        if ($user->user != null) {
            if ($user->user->firebase_tokens->count() > 0) {

                $message = 'KSH '.number_format($user->amount, 2).' received for as payments for '.$user->vehicle->plate;
                $tokens = $user->user->firebase_tokens->pluck('firebase_token');
                foreach ($tokens as $token) {
                    dispatch(new SendFCMJob($token, $title, $message, 'qrcode_payments', $qrcode_payment_id));
                }

            }
        }
    }
}
