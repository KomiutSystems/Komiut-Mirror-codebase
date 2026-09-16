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

    /**
     * Which of the two fields is the payer's phone, and which the reference?
     *
     * A Kenyan MSISDN is `254` + `7` or `1` + eight digits. Nothing else Co-op
     * puts in these positions looks like that: a till is 6-7 digits, a paybill
     * alias is 7, a bank account is 14 and starts `011`. When neither field
     * looks like a phone the narration is one of the bank's own sweeps
     * (`Loan Recovery For...`, `EXCISE`); the positions are then read as they
     * come, which is what happened before, and the recorder tolerates it.
     *
     * @return array{0: string, 1: string} [reference, phone]
     */
    private static function referenceAndPhone(string $first, string $second): array
    {
        $isPhone = static fn (string $v): bool => (bool) preg_match('/^254[17]\d{8}$/', $v);

        return $isPhone($first) && ! $isPhone($second) ? [$second, $first] : [$first, $second];
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

        // The feed is every movement on the SACCO's account, not just fares.
        // The bank's own debits -- loan recovery, excise, commission, the
        // monthly "Kamilisha" fees -- arrive on it with EventType DEBIT and a
        // plain-text narration: 203 of them in the 30 days to 2026-09-16,
        // KES 663,762 against KES 680,701 of fares. Recording those as
        // payments would show a SACCO its own loan repayments as takings. The
        // legacy job dropped them only by accident (no phone => the insert
        // failed). Here they are acknowledged -- the bank must not retry -- and
        // kept in mpesa_logs, and nothing else.
        $eventType = strtoupper(trim((string) $request->input('EventType', 'CREDIT')));
        if ($eventType !== 'CREDIT') {
            if (str_contains((string) $request->Narration, '~')) {
                // A debit carrying a fare's receipt is the bank reversing that
                // fare (3 in the same 30 days, KES 16,461). Nothing is undone
                // here -- that is a decision, not a parse -- but it is not
                // allowed to pass silently either.
                Log::warning('coop debit against a fare receipt', [
                    'narration' => $request->Narration, 'amount' => $request->Amount,
                    'bank_id' => $request->input('TransactionId'),
                ]);
            }

            return response()->json(["MessageCode" => "200", "Message" => "Successfully received data"]);
        }

        $amount = $request->Amount;
        $narration = array_map('trim', explode("~", $request->Narration));

        // A credit with no tilde is not a fare: an inbound transfer or a
        // reversal, described in prose. Its narration is not unique -- the
        // recorder dedupes on TransID and would keep only the first of them --
        // so the bank's own id for the movement is the receipt.
        $transId = count($narration) > 1
            ? $narration[0]
            : (string) ($request->input('TransactionId') ?: $request->input('PaymentRef') ?: $narration[0]);

        // Positions [1] and [2] are the payer's phone and the reference the bank
        // attributes by -- in EITHER order. Every till payment and every paybill
        // paid against an alias come as `TransID~ref~phone~...`; a paybill paid
        // against the SACCO's own bank account comes as `TransID~phone~account~...`.
        // Both are real: the second is how Co-op ran the Metrotrans onboarding
        // test on 2026-09-16, and the legacy parser -- fixed at [1]=ref -- filed
        // that payment with the phone as the shortcode and the bank account as
        // the MSISDN. The phone is the one field with a shape of its own, so
        // find it rather than trust the position.
        [$businessShortCode, $phone] = self::referenceAndPhone($narration[1] ?? '', $narration[2] ?? '');

        // Paybill via Coop's shared 400200 inserts an "MPESAC2B_<paybill>" tag at [3], shifting the name to [4].
        $isPaybill = isset($narration[3]) && preg_match('/^MPESAC2B_\d+$/i', $narration[3]);
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

        // First / middle / last as the legacy rows already in `mpesas` have
        // them: a two-word name is first + LAST, not first + middle.
        $nameParts  = array_values(array_filter(explode(' ', trim($rawName)), fn($v) => $v !== ''));
        $firstname  = array_shift($nameParts) ?? '';
        $lastname   = count($nameParts) > 0 ? array_pop($nameParts) : '';
        $middlename = implode(' ', $nameParts);
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
