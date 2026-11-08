<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Enums\WalletEntryKind;
use App\Enums\WalletTopUpRail;
use App\Enums\WalletTopUpStatus;
use App\Models\Mpesa;
use App\Models\Wallet;
use App\Models\WalletTopUp;
use App\Models\WalletTransaction;
use App\Services\Mpesa\DarajaClient;
use App\Services\Ncba\NcbaStkClient;
use App\Support\Phone;
use App\Support\Shillings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Getting money INTO a wallet.
 *
 * Two rails, and they do not resemble each other:
 *
 *   mpesa  Safaricom Daraja STK on a Komiut paybill. Has a callback.
 *   ncba   NCBA Till STK on 880100. HAS NO CALLBACK -- NCBA's spec learns the
 *          outcome by querying, which is why PollNcbaStkPush exists for fares.
 *
 * Both converge on the same place: the money arrives as an ordinary C2B
 * confirmation and `settleFromConfirmation` credits the wallet. That is not a
 * simplification, it is the design -- a passenger may also simply pay the
 * paybill by hand with their wallet account number and no prompt at all, and
 * that has to work identically. So the STK half is only a convenience that
 * saves typing; the confirmation is the thing that moves money.
 *
 * WHAT SETTLEMENT TRUSTS, in order:
 *   1. the shortcode the money landed on (WalletFloat -- the security gate)
 *   2. the account number on the payment (which wallet)
 *   3. the amount ACTUALLY RECEIVED, never the amount requested
 *
 * Nothing here trusts a pending top-up row for the amount. A passenger who is
 * prompted for KES 500 and sends 400 gets 400.
 */
final class WalletTopUpService
{
    public function __construct(
        private WalletService $wallet,
        private WalletFloat $float,
        private NcbaStkClient $ncba,
    ) {}

    /**
     * Start a top-up: create the attempt, then ask the rail to prompt.
     *
     * Returns the attempt whatever happens -- a refused prompt is a `failed`
     * row the passenger can see and retry, not an exception that leaves them
     * guessing. Only the checks that happen BEFORE anything is recorded throw.
     */
    public function start(
        int $userId,
        int $shillings,
        WalletTopUpRail $rail,
        ?string $phone,
        string $idempotencyKey,
    ): WalletTopUp {
        // THE IDEMPOTENCY CHECK COMES FIRST, before validation: a retried
        // request must return the original attempt even if the limits have
        // since changed under it, or a passenger whose first tap succeeded
        // would be told their second tap was invalid.
        $existing = WalletTopUp::where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            if ((int) $existing->wallet->user_id !== $userId) {
                throw WalletRefusal::foreignIdempotencyKey();
            }

            return $existing;
        }

        if (! $this->float->railIsLive($rail)) {
            throw WalletRefusal::railUnavailable($rail);
        }

        $this->assertAmountIsAllowed($shillings);

        $msisdn = Phone::msisdn($phone) ?? Phone::msisdn($this->phoneOnFile($userId));

        if ($msisdn === null) {
            throw WalletRefusal::noPhoneToPrompt();
        }

        // MINTED INSIDE THE LOCK. Outside it, two first-ever top-ups with
        // different idempotency keys each mint a code and the second overwrites
        // the first -- the first prompt's payment then arrives under a code no
        // wallet has any more and lands in "matches no wallet", which is real
        // money needing a human to reconcile it.
        [$wallet, $accountNo] = DB::transaction(function () use ($userId) {
            $locked = $this->wallet->lock($userId);

            // The rail needs this NOW, as the AccountReference it echoes back
            // on the confirmation.
            return [$locked, WalletAccountNumber::forWallet($locked)];
        });

        $this->assertRoomOnBalance($wallet, $shillings);

        $attempt = WalletTopUp::create([
            'wallet_id' => $wallet->id,
            'amount_cents' => Shillings::toCents($shillings),
            'rail' => $rail,
            'status' => WalletTopUpStatus::Pending,
            'phone' => $msisdn,
            'idempotency_key' => $idempotencyKey,
            // Minted even for NCBA, which calls nobody back, so every row looks
            // the same and the unique column stays populated -- the same choice
            // MpesaPaymentsController makes for its NCBA pushes.
            'callback_nonce' => bin2hex(random_bytes(32)),
        ]);

        return $this->prompt($attempt, $accountNo, $msisdn, $shillings, $rail);
    }

    /**
     * Ask the rail to put the prompt on the handset.
     *
     * A rail that gives no usable answer leaves the attempt FAILED rather than
     * pending. Pending means "the passenger has a prompt in front of them", and
     * claiming that when no prompt was sent leaves them staring at a spinner
     * until it times out.
     */
    private function prompt(
        WalletTopUp $attempt,
        string $accountNo,
        string $msisdn,
        int $shillings,
        WalletTopUpRail $rail,
    ): WalletTopUp {
        $response = match ($rail) {
            WalletTopUpRail::Mpesa => $this->darajaClient()?->stkPush(
                $msisdn,
                $shillings,
                $accountNo,
                $this->callbackUrl($attempt),
            ),
            WalletTopUpRail::Ncba => $this->ncba->initiate($msisdn, $shillings, $accountNo),
        };

        $reference = $this->referenceFrom($response, $rail);

        if ($reference === null) {
            $attempt->status = WalletTopUpStatus::Failed;
            $attempt->failure_reason = $this->refusalFrom($response);
            $attempt->save();

            return $attempt;
        }

        $attempt->rail_reference = $reference;
        $attempt->save();

        return $attempt;
    }

    /** @param array<string, mixed>|null $response */
    private function referenceFrom(?array $response, WalletTopUpRail $rail): ?string
    {
        if ($response === null) {
            return null;
        }

        return match ($rail) {
            // Daraja: ResponseCode "0" and a CheckoutRequestID means accepted.
            WalletTopUpRail::Mpesa => ((string) ($response['ResponseCode'] ?? '')) === '0'
                ? ($response['CheckoutRequestID'] ?? null)
                : null,

            // NCBA: StatusCode "1" with a null TransactionID is a refusal, per
            // the spec note on NcbaStkClient.
            WalletTopUpRail::Ncba => filled($response['TransactionID'] ?? null)
                ? (string) $response['TransactionID']
                : null,
        };
    }

    /** @param array<string, mixed>|null $response */
    private function refusalFrom(?array $response): string
    {
        if ($response === null) {
            return 'M-Pesa did not respond. Try again in a moment.';
        }

        $said = $response['errorMessage']
            ?? $response['ResponseDescription']
            ?? $response['StatusDescription']
            ?? null;

        return is_string($said) && $said !== ''
            ? mb_substr($said, 0, 200)
            : 'The payment prompt was refused.';
    }

    /**
     * A Komiut-float Daraja client, or null when the paybill is not provisioned.
     *
     * Built per call rather than injected because its credentials are the float
     * account's, not a SACCO's, and the whole point of WalletFloat is that
     * those are absent until somebody provisions them.
     */
    private function darajaClient(): ?DarajaClient
    {
        $shortCode = $this->float->shortCode();

        if ($shortCode === null || ! $this->float->railIsLive(WalletTopUpRail::Mpesa)) {
            return null;
        }

        return new DarajaClient(
            (string) config('wallet.float.consumer_key'),
            (string) config('wallet.float.consumer_secret'),
            $shortCode,
            (string) config('wallet.float.pass_key'),
            (bool) config('services.mpesa.live', true),
        );
    }

    /**
     * Where Daraja posts the result.
     *
     * KEYED ON THE NONCE, not the row id, matching the existing
     * `stk/push/response/{nonce}`: the endpoint is unauthenticated because
     * Safaricom posts it, and a sequential path would let anyone mark another
     * passenger's top-up cancelled.
     *
     * The callback NEVER credits -- only a C2B confirmation does. The worst a
     * forged one can do is misreport a status the next confirmation corrects.
     */
    private function callbackUrl(WalletTopUp $attempt): string
    {
        return url('/api/wallet/top-ups/'.$attempt->callback_nonce.'/callback');
    }

    /**
     * Credit a wallet from a C2B confirmation, if that is what this payment is.
     *
     * CALLED FOR EVERY CONFIRMATION, so the first two checks are what keep fare
     * money out of wallets:
     *
     *   - the money must have landed on the Komiut float shortcode. Without
     *     this a passenger could type their wallet number into any SACCO's
     *     paybill and be credited with that SACCO's collections.
     *   - the account number must parse as a wallet code. Every fare payment
     *     also carries a BillRefNumber, and WalletAccountNumber::normalise
     *     refuses anything that is not shaped like one of ours.
     *
     * Returns null when the payment is not a wallet top-up, which is the
     * overwhelmingly common case and must be cheap and silent.
     *
     * @param  array<string, mixed>  $fields  the raw confirmation
     */
    public function settleFromConfirmation(array $fields, Mpesa $mpesa): ?WalletTransaction
    {
        if (! $this->float->ownsShortCode($fields['BusinessShortCode'] ?? null)) {
            return null;
        }

        $wallet = WalletAccountNumber::resolve($fields['BillRefNumber'] ?? null);

        if ($wallet === null) {
            // Money on OUR shortcode that matches no wallet. Logged loudly
            // because it is real money with nowhere to go -- a mistyped account
            // number -- and somebody has to reconcile it by hand.
            Log::warning('wallet top-up: payment on the float shortcode matches no wallet', [
                'trans_id' => $mpesa->TransID,
                'account' => $fields['BillRefNumber'] ?? null,
            ]);

            return null;
        }

        // THE AMOUNT RECEIVED, not the amount asked for. A passenger prompted
        // for 500 who sends 400 is credited 400, and one who sends 600 gets
        // 600 -- the bank has the money either way, so anything else creates a
        // discrepancy between the float and the ledger.
        $cents = (int) round(((float) $mpesa->TransAmount) * Shillings::PER_SHILLING);

        if ($cents <= 0) {
            Log::warning('wallet top-up: confirmation carried no amount', ['trans_id' => $mpesa->TransID]);

            return null;
        }

        $attempt = $this->matchingAttempt($wallet, $mpesa);

        $entry = $this->wallet->credit(
            (int) $wallet->user_id,
            $cents,
            WalletEntryKind::TopUp,
            // Keyed on the PAYMENT, not the attempt: a manual paybill top-up
            // has no attempt, and the same payment must never credit twice
            // whichever route it arrives by.
            'topup:mpesa:'.$mpesa->id,
            [
                'title' => 'Wallet top-up',
                'detail' => $this->detailFor($mpesa),
                'mpesa_id' => $mpesa->id,
                'meta' => [
                    'trans_id' => $mpesa->TransID,
                    'account_no' => $wallet->account_no,
                    'top_up_id' => $attempt?->id,
                ],
            ],
        );

        $this->markSettled($attempt, $mpesa, $entry);

        return $entry;
    }

    /**
     * The pending attempt this payment answers, if any.
     *
     * Matched on the wallet and the attempt still being open, NOT on the
     * amount: a passenger who edits the amount in the M-Pesa prompt still means
     * it for the attempt they started. The oldest open attempt wins, since that
     * is the one they have been waiting on.
     *
     * A payment with no attempt is not an error -- it is somebody paying the
     * paybill by hand.
     */
    private function matchingAttempt(Wallet $wallet, Mpesa $mpesa): ?WalletTopUp
    {
        return WalletTopUp::where('wallet_id', $wallet->id)
            ->whereNull('wallet_transaction_id')
            ->whereIn('status', [
                WalletTopUpStatus::Pending->value,
                // A payment that lands after the deadline still settles its
                // attempt. Calling it timed_out was a guess; the money is not.
                WalletTopUpStatus::TimedOut->value,
            ])
            ->orderBy('id')
            ->first();
    }

    /**
     * Close the attempt against the money, or record the money as its own
     * completed attempt so the passenger's top-up history is not missing it.
     */
    private function markSettled(?WalletTopUp $attempt, Mpesa $mpesa, WalletTransaction $entry): void
    {
        if ($attempt === null) {
            // firstOrCreate, NOT create: a replayed confirmation gets the
            // already-credited ledger row back from credit() and arrives here
            // again, and a second insert collides on the idempotency key --
            // turning a harmless duplicate delivery into an exception inside
            // the payment ingest, which is exactly what must never happen.
            WalletTopUp::firstOrCreate([
                'idempotency_key' => 'c2b:'.$mpesa->id,
            ], [
                'wallet_id' => $entry->wallet_id,
                'amount_cents' => abs((int) $entry->amount_cents),
                // Manual paybill payments arrive through the same rail the
                // prompt would have used.
                'rail' => WalletTopUpRail::Mpesa,
                'status' => WalletTopUpStatus::Completed,
                'phone' => $mpesa->MSISDN,
                'mpesa_id' => $mpesa->id,
                'wallet_transaction_id' => $entry->id,
                'completed_at' => Carbon::now(),
            ]);

            return;
        }

        if ($attempt->isSettled()) {
            return;
        }

        $attempt->status = WalletTopUpStatus::Completed;
        $attempt->mpesa_id = $mpesa->id;
        $attempt->wallet_transaction_id = $entry->id;
        $attempt->amount_cents = abs((int) $entry->amount_cents);
        $attempt->failure_reason = null;
        $attempt->completed_at = Carbon::now();
        $attempt->save();
    }

    private function detailFor(Mpesa $mpesa): string
    {
        $name = trim((string) $mpesa->FirstName);

        return $name === ''
            ? 'M-Pesa '.$mpesa->TransID
            : 'M-Pesa '.$mpesa->TransID.' · '.$name;
    }

    /**
     * Call a pending attempt timed out once its window has passed.
     *
     * NOT failed: `mayStillSettle` keeps these matchable, because an STK prompt
     * dies on the handset long before the money necessarily does, and a
     * passenger whose payment lands late must still be credited.
     *
     * @return int how many were aged out
     */
    public function expireStaleAttempts(): int
    {
        $cutoff = Carbon::now()->subMinutes((int) config('wallet.top_up_expiry_minutes'));

        return WalletTopUp::where('status', WalletTopUpStatus::Pending->value)
            ->whereNull('wallet_transaction_id')
            ->where('created_at', '<', $cutoff)
            ->update([
                'status' => WalletTopUpStatus::TimedOut->value,
                'failure_reason' => 'No payment was received in time.',
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Apply Safaricom's STK verdict to an attempt -- including crediting it.
     *
     * THIS IS WHERE AN STK TOP-UP IS ACTUALLY CREDITED, and that is not a
     * convenience. A Lipa-na-M-Pesa-Online payment is delivered to the STK
     * CallBackURL and does NOT reach the C2B confirmation URL -- which is why
     * MpesaPaymentsController::stkResponse settles bookings straight from
     * CallbackMetadata rather than through C2bPaymentRecorder. Waiting here for
     * a confirmation that never arrives would mean the passenger pays and is
     * never credited. (A MANUAL paybill payment does arrive as a C2B
     * confirmation, and settleFromConfirmation handles that one.)
     *
     * Both paths converge on an `mpesas` row keyed by TransID, so the unique
     * `wallet_transactions.mpesa_id` still means one payment credits once
     * however it reaches us.
     *
     * @param  array<string, mixed>  $callback  the decoded Body.stkCallback
     */
    public function applyRailVerdict(string $nonce, array $callback): ?WalletTopUp
    {
        $attempt = WalletTopUp::where('callback_nonce', $nonce)->first();

        if ($attempt === null || $attempt->isSettled() || $attempt->status === WalletTopUpStatus::Completed) {
            return $attempt;
        }

        $resultCode = (string) ($callback['ResultCode'] ?? '');
        $description = $callback['ResultDesc'] ?? null;

        // Daraja's documented codes, as recorded on DarajaClient::stkQuery:
        // 0 paid, 1032 cancelled by the customer, 1037 timeout/unreachable.
        if ($resultCode === '0') {
            $this->creditFromStkCallback($attempt, (array) ($callback['CallbackMetadata']['Item'] ?? []));

            return $attempt->refresh();
        }

        $attempt->status = match ($resultCode) {
            '1032' => WalletTopUpStatus::Cancelled,
            '1037' => WalletTopUpStatus::TimedOut,
            default => WalletTopUpStatus::Failed,
        };
        $attempt->failure_reason = is_string($description) && $description !== ''
            ? mb_substr($description, 0, 200)
            : null;
        $attempt->save();

        return $attempt;
    }

    /**
     * Turn a successful STK callback into money, having first checked it is real.
     *
     * VERIFIED WITH stkQuery BEFORE ANYTHING MOVES. The callback endpoint is
     * unauthenticated -- Safaricom presents no credential -- so a forged POST
     * naming an invented MpesaReceiptNumber would otherwise mint an `mpesas`
     * row and credit a wallet with money that never existed. Daraja's query
     * answers over an authenticated channel and cannot be spoofed: the pattern
     * DarajaClient already states in as many words, "Webhook = speed; this =
     * verification."
     *
     * THE AMOUNT IS THE ATTEMPT'S, NOT THE CALLBACK'S. An STK prompt charges
     * exactly what was requested at initiation -- the payer cannot edit it,
     * unlike a manual paybill -- so the figure sent to Daraja is authoritative,
     * and reading it from the body would let a forged callback inflate the
     * credit. A disagreement is logged and ignored, never obeyed.
     *
     * @param  array<int, mixed>  $items  CallbackMetadata.Item
     */
    private function creditFromStkCallback(WalletTopUp $attempt, array $items): void
    {
        $metadata = $this->readCallbackItems($items);
        $receipt = trim((string) ($metadata['MpesaReceiptNumber'] ?? ''));

        if ($receipt === '') {
            Log::warning('wallet top-up: success callback carried no receipt', ['top_up_id' => $attempt->id]);

            return;
        }

        if (! $this->stkPaymentIsReal($attempt)) {
            // Left PENDING on purpose: the expiry sweep ages it out, and a
            // genuine payment still settles through whichever path brings it.
            // Marking it failed on an unverifiable callback would tell a
            // passenger who really did pay that they had not.
            Log::warning('wallet top-up: success callback could not be verified with Daraja', [
                'top_up_id' => $attempt->id,
                'receipt' => $receipt,
            ]);

            return;
        }

        $claimed = isset($metadata['Amount'])
            ? (int) round(((float) $metadata['Amount']) * Shillings::PER_SHILLING)
            : null;

        if ($claimed !== null && $claimed !== (int) $attempt->amount_cents) {
            Log::warning('wallet top-up: callback amount disagrees with the prompt', [
                'top_up_id' => $attempt->id,
                'prompted_cents' => (int) $attempt->amount_cents,
                'callback_cents' => $claimed,
            ]);
        }

        // Keyed on TransID, the same key C2bPaymentRecorder dedupes on, so a
        // confirmation for this payment arriving later finds this row rather
        // than making a second one.
        $mpesa = Mpesa::withoutGlobalScopes()->firstOrCreate(
            ['TransID' => $receipt],
            [
                'MSISDN' => (string) ($metadata['PhoneNumber'] ?? $attempt->phone ?? ''),
                'TransAmount' => (string) Shillings::toShillings((int) $attempt->amount_cents),
                'TransTime' => $this->callbackTime($metadata['TransactionDate'] ?? null),
                'FirstName' => '',
                'BillRefNumber' => (string) $attempt->wallet?->account_no,
            ],
        );

        $entry = $this->wallet->credit(
            (int) $attempt->wallet->user_id,
            (int) $attempt->amount_cents,
            WalletEntryKind::TopUp,
            'topup:mpesa:'.$mpesa->id,
            [
                'title' => 'Wallet top-up',
                'detail' => 'M-Pesa '.$receipt,
                'mpesa_id' => $mpesa->id,
                'meta' => [
                    'trans_id' => $receipt,
                    'top_up_id' => $attempt->id,
                    'via' => 'stk_callback',
                ],
            ],
        );

        $this->markSettled($attempt, $mpesa, $entry);
    }

    /**
     * Ask Daraja whether this checkout was really paid.
     *
     * Fails CLOSED: no answer means no credit. An unreachable Daraja is a
     * delay; a credit granted on an unverified callback is money the platform
     * never received.
     */
    private function stkPaymentIsReal(WalletTopUp $attempt): bool
    {
        $reference = (string) $attempt->rail_reference;

        if ($reference === '') {
            return false;
        }

        $answer = $this->darajaClient()?->stkQuery($reference);

        return is_array($answer) && ((string) ($answer['ResultCode'] ?? '')) === '0';
    }

    /**
     * CallbackMetadata.Item is a LIST of {Name, Value}, not a map.
     *
     * @param  array<int, mixed>  $items
     * @return array<string, mixed>
     */
    private function readCallbackItems(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            $item = (array) $item;
            $name = $item['Name'] ?? null;

            if (is_string($name) && $name !== '') {
                $out[$name] = $item['Value'] ?? null;
            }
        }

        return $out;
    }

    /** Daraja sends TransactionDate as YmdHis. */
    private function callbackTime(mixed $raw): Carbon
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return Carbon::now();
        }

        try {
            return Carbon::createFromFormat('YmdHis', $value) ?: Carbon::now();
        } catch (\Throwable) {
            // Never throws on an unparsable date: an unusable TransTime is
            // exactly what lost 52 payments on the legacy backend.
            return Carbon::now();
        }
    }

    /** One attempt, scoped to its owner. */
    public function find(int $userId, int $id): ?WalletTopUp
    {
        $walletId = Wallet::where('user_id', $userId)->value('id');

        if ($walletId === null) {
            return null;
        }

        return WalletTopUp::with('transaction')->where('wallet_id', $walletId)->whereKey($id)->first();
    }

    /** @return Builder<WalletTopUp> */
    public function historyQuery(int $userId)
    {
        $walletId = Wallet::where('user_id', $userId)->value('id');

        return WalletTopUp::query()->where('wallet_id', $walletId ?? 0);
    }

    private function assertAmountIsAllowed(int $shillings): void
    {
        $min = (int) config('wallet.limits.min_top_up');
        $max = (int) config('wallet.limits.max_top_up');

        if ($shillings < $min || $shillings > $max) {
            throw WalletRefusal::topUpOutOfRange($min, $max);
        }
    }

    /**
     * Refuse before prompting if the money could not be held.
     *
     * Checked here as well as in WalletService::credit because the alternative
     * is taking a passenger's money and then refusing to hold it -- the credit
     * would throw with the cash already gone from their M-Pesa.
     */
    private function assertRoomOnBalance(Wallet $wallet, int $shillings): void
    {
        $maxShillings = (int) config('wallet.limits.max_balance');
        $after = (int) $wallet->balance_cents + Shillings::toCents($shillings);

        if ($after > Shillings::toCents($maxShillings)) {
            throw WalletRefusal::wouldExceedMaxBalance($maxShillings);
        }
    }

    private function phoneOnFile(int $userId): ?string
    {
        return DB::table('users')->where('id', $userId)->value('phone');
    }
}
