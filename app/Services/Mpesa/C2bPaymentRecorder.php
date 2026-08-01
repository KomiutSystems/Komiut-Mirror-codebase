<?php

declare(strict_types=1);

namespace App\Services\Mpesa;

use App\Models\Mpesa;
use App\Models\Summary;
use App\Models\Transaction;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records a single C2B (till/paybill) payment confirmation — the save chain
 * shared by every confirmation entry point (NCBA's two REST variants, and the
 * Pull API reconciler): dedupe the Mpesa row by TransID, attach it to a
 * Transaction, roll it into the vehicle's daily Summary.
 *
 * Wrapped in try/catch on purpose. A production incident on the legacy backend
 * traced 52 confirmed-received, confirmed-authenticated payments that were never
 * saved: an unparsable TransTime threw mid-save, AFTER the raw payload was
 * logged, so Safaricom got a 500 instead of the expected ResultCode JSON — and
 * the payment vanished from our side while the money was already gone from the
 * customer's. Catching here means the worst case is "recorded without a vehicle
 * link" (visible, fixable), never "vanished" (invisible, unfixable after the fact).
 *
 * Vehicle resolution is a caller-supplied callback rather than hard-coded here:
 * the two current C2B entry points resolve a vehicle differently for historical
 * reasons (till_number vs merchant_short_code), and collapsing that here would
 * silently change behaviour one of them is already tested against.
 */
final class C2bPaymentRecorder
{
    /** @param callable(string $businessShortCode, ?string $billRefNumber): ?Vehicle $resolveVehicle */
    public function record(array $fields, callable $resolveVehicle): C2bRecordResult
    {
        try {
            return $this->attempt($fields, $resolveVehicle);
        } catch (Throwable $e) {
            Log::error('c2b payment recording failed', [
                'trans_id' => $fields['TransID'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return C2bRecordResult::failed($e->getMessage());
        }
    }

    private function attempt(array $fields, callable $resolveVehicle): C2bRecordResult
    {
        $transId = trim((string) ($fields['TransID'] ?? ''));
        $amount = (float) ($fields['TransAmount'] ?? 0);

        if ($transId === '') {
            return C2bRecordResult::failed('missing TransID');
        }
        if ($amount <= 0) {
            return C2bRecordResult::failed('invalid amount');
        }

        $mpesa = Mpesa::where('TransID', $transId)->first();
        $mpesa ??= new Mpesa();

        $mpesa->TransID = $transId;
        $mpesa->MSISDN = (string) ($fields['MSISDN'] ?? '');
        $mpesa->TransAmount = $amount;
        $mpesa->TransTime = $this->parseTransTime($fields['TransTime'] ?? null);
        $mpesa->FirstName = (string) ($fields['FirstName'] ?? '');
        $mpesa->MiddleName = (string) ($fields['MiddleName'] ?? '');
        $mpesa->LastName = (string) ($fields['LastName'] ?? '');
        $mpesa->BusinessShortCode = (string) ($fields['BusinessShortCode'] ?? '');
        $mpesa->TransactionType = (string) ($fields['TransactionType'] ?? '');
        $mpesa->ThirdPartyTransID = (string) ($fields['ThirdPartyTransID'] ?? '');
        $mpesa->InvoiceNumber = (string) ($fields['InvoiceNumber'] ?? '');
        $mpesa->BillRefNumber = (string) ($fields['BillRefNumber'] ?? '');
        $mpesa->save();

        // withoutGlobalScopes: Transaction is brand/sacco-scoped via its vehicle,
        // but payment recording is a system op keyed on the unique mpesa_id — a
        // scoped lookup would hide a vehicle-less row and double-record it.
        $transaction = Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->first();
        if ($transaction === null) {
            $transaction = new Transaction();
            $transaction->mpesa_id = $mpesa->id;
            $transaction->trans_date = $mpesa->TransTime;
            $transaction->amount = $amount;

            $vehicle = $resolveVehicle((string) ($fields['BusinessShortCode'] ?? ''), $fields['BillRefNumber'] ?? null);
            if ($vehicle !== null) {
                $transaction->vehicle_id = $vehicle->id;
                $this->rollIntoSummary($vehicle, $mpesa);
                $transaction->summarized = true;
            }
            $transaction->save();

            return C2bRecordResult::created($mpesa, $transaction);
        }

        return C2bRecordResult::duplicate($mpesa, $transaction);
    }

    private function rollIntoSummary(Vehicle $vehicle, Mpesa $mpesa): void
    {
        $date = $mpesa->TransTime instanceof Carbon
            ? $mpesa->TransTime->format('Y-m-d')
            : Carbon::parse((string) $mpesa->TransTime)->format('Y-m-d');

        $summary = Summary::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->where('trans_date', $date)->first();
        if ($summary === null) {
            $summary = new Summary();
            $summary->vehicle_id = $vehicle->id;
            $summary->mpesa_amount = 0;
            $summary->cash_amount = 0;
            $summary->mpesa_txn = 0;
            $summary->cash_txn = 0;
            $summary->trans_date = $date;
        }
        $summary->mpesa_amount += $mpesa->TransAmount;
        $summary->mpesa_txn += 1;
        $summary->save();
    }

    /**
     * Safaricom's TransTime is normally YmdHis, but an unexpected value must
     * never crash the save — that is exactly the failure mode that lost real
     * payments (see class docblock). Falls back to "now" rather than throwing.
     */
    private function parseTransTime(mixed $raw): Carbon
    {
        if ($raw === null || $raw === '') {
            return Carbon::now();
        }
        try {
            return Carbon::parse((string) $raw);
        } catch (Throwable) {
            Log::warning('c2b payment: unparseable TransTime, defaulting to now', ['raw' => $raw]);

            return Carbon::now();
        }
    }
}
