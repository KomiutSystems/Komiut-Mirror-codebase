<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Models\CoopMpesaStkCallback;
use App\Models\MpesaLog;
use App\Models\Vehicle;
use App\Services\Mpesa\C2bPaymentRecorder;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CoopRestPaymentsController extends Controller
{
    public function __construct(private readonly C2bPaymentRecorder $recorder) {}

    /**
     * Which bus does this Co-op confirmation belong to?
     *
     * withoutGlobalScopes, for the reason C2bPaymentRecorder already documents
     * for Transaction and Summary: recording a payment is a SYSTEM operation.
     * There is no authenticated user, so SaccoScope and FinancierScope are
     * already no-ops — but BrandScope is not. It keys on Context, which the
     * `brand.route` middleware sets from the {brand} URL segment, so a
     * confirmation arriving under one brand could not see a vehicle belonging to
     * another. The identical bug on the per-till C2B path was recording 40.9% of
     * one day's money against vehicle_id NULL — every vehicle on brand `safiri`,
     * 2,576 transactions and KES 159,947, measured 2026-08-26.
     *
     * Whose money this is, is decided by the shortcode the bank sends. The brand
     * of the URL the callback happened to arrive on is not evidence about that,
     * so it must not narrow the search.
     *
     * The multi-match guard matches the other two paths. Production has three
     * ambiguous merchant_short_code values (880100 across 34 vehicles, 331872
     * across 9, and '0' across 2); `->first()` on any of them is a coin toss with
     * someone's takings. Unattributed is recoverable and visible; mis-attributed
     * is neither.
     */
    private function resolveVehicle(string $shortCode): ?Vehicle
    {
        if ($shortCode === '') {
            return null;
        }

        // take(2): enough to know whether it is ambiguous, without loading a fleet.
        $matches = Vehicle::withoutGlobalScopes()
            ->where('merchant_short_code', $shortCode)
            ->take(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function coopMpesaPayments(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'Amount' => 'required|numeric|min:1',
            'TransactionDate' => 'required|string',
            'Narration' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->messages()], 400);
        }
        $mpesaLog = new MpesaLog;
        $mpesaLog->log = json_encode($request->all());
        $mpesaLog->save();

        $amount = $request->Amount;
        $narration = explode("~", $request->Narration);
        $transId           = $narration[0] ?? '';
        $businessShortCode = $narration[1] ?? '';
        $phone             = $narration[2] ?? '';

        // Paybill via Coop's shared 400200 inserts an "MPESAC2B_<paybill>" tag at [3], shifting the name to [4].
        $isPaybill = isset($narration[3]) && preg_match('/^MPESAC2B_\d+$/i', trim($narration[3]));
        $topLevelDate = Carbon::parse(str_replace('+', ' ', $request->TransactionDate));

        if ($isPaybill) {
            $rawName         = $narration[4] ?? '';
            $billRef         = $businessShortCode;
            $transactionType = 'Pay Bill';
            $transDate       = $topLevelDate;
        } else {
            $rawName         = $narration[3] ?? '';
            $billRef         = '';
            $transactionType = 'Buy Goods Online';
            $transDate       = $topLevelDate;
            try {
                if (!empty($narration[4])) {
                    $transDate = Carbon::parse($narration[4]);
                }
            } catch (Exception $e) {
                $transDate = $topLevelDate;
            }
        }

        $nameParts  = array_values(array_filter(explode(' ', trim($rawName)), fn($v) => $v !== ''));
        $firstname  = $nameParts[0] ?? '';
        $middlename = $nameParts[1] ?? '';
        $lastname   = $nameParts[2] ?? '';
        $mpesaLog->trans_id = $transId;
        $mpesaLog->save();

        // The save chain is C2bPaymentRecorder — the same one the NCBA and the
        // per-till C2B paths use. This method hand-rolled its own, and inherited
        // every defect that class exists to fix:
        //
        //   - a read-modify-write on `summaries` with no lock, which loses one of
        //     two concurrent payments to the same bus on the same day, and (before
        //     summaries gained its UNIQUE (vehicle_id, trans_date)) left duplicate
        //     rows that SummariesAPIController then SUMs;
        //   - no try/catch, so one unparseable field threw mid-save and lost a
        //     payment that had already been received. That is not hypothetical: it
        //     is the incident recorded in C2bPaymentRecorder's own docblock, where
        //     52 confirmed payments vanished because an unparsable TransTime threw
        //     AFTER the raw payload was logged.
        //
        // The narration parsing above stays here, because it is Co-op specific —
        // only this bank packs the whole payment into one tilde-delimited string.
        // Everything after the parse is the same job every other C2B path does, so
        // it belongs in the one place that does it correctly.
        $result = $this->recorder->record([
            'TransID' => $transId,
            'MSISDN' => $phone,
            'TransAmount' => $amount,
            'TransTime' => $transDate,
            'FirstName' => $firstname,
            'MiddleName' => $middlename,
            'LastName' => $lastname,
            'BusinessShortCode' => $businessShortCode,
            'ThirdPartyTransID' => '',
            'InvoiceNumber' => '',
            'BillRefNumber' => $billRef,
            'TransactionType' => $transactionType,
        ], function (string $shortCode, ?string $billRef): ?Vehicle {
            // $billRef is deliberately unused. It exists in the recorder's
            // contract for NCBA's 880100 aggregator, where the paybill identifies
            // the BANK and the bus is carried in BillRefNumber. Co-op has no such
            // case: on the buy-goods shape the field is empty, and on the paybill
            // shape the parse above sets it to the shortcode we already resolve on.
            return $this->resolveVehicle($shortCode);
        });

        if (! $result->ok) {
            // Co-op is told the same thing either way, deliberately. The money has
            // already arrived; re-sending it would not help, and a non-2xx here
            // buys a retry storm rather than a recovered payment. The raw body is
            // in mpesa_logs above and the reason is in the application log.
            Log::error('coop payment recording failed', [
                'trans_id' => $transId,
                'short_code' => $businessShortCode,
                'error' => $result->error,
            ]);
        }

        return response()->json(["MessageCode" => "200", "Message" => "Successfully received data"]);
    }

    public function coopMpesaStkCallback(Request $request){
        $content = json_decode($request->getContent());
        $coopMpesaCallback = new CoopMpesaStkCallback();
        $coopMpesaCallback->callback = json_encode($content);
        if($coopMpesaCallback->save()){
            return response()->json(['success'=>'Success']);
        }else{
            return response()->json(['error'=>'Unable to save response!'], 400);
        }
    }

    public function coopMpesaStk(Request $request){
        //to be implemented
    }
}
