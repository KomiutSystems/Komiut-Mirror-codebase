<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Wallet;

use App\Enums\WalletEntryKind;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletSettlement;
use App\Models\WalletTopUp;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletAccountNumber;
use App\Services\Wallet\WalletOpsService;
use App\Services\Wallet\WalletPaymentRefused;
use App\Services\Wallet\WalletRefusal;
use App\Services\Wallet\WalletSettlementService;
use App\Support\Phone;
use App\Support\Shillings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * One passenger's wallet, and the operator's hands on it.
 *
 * WHAT A SUPPORT PERSON ACTUALLY NEEDS. A passenger rings up saying "I topped
 * up and it never arrived" or "I was charged twice". Answering that needs the
 * balance, the ledger in order, the top-up attempts with their failure reasons,
 * and the ability to act -- in one place, found by the only identifier the
 * passenger knows, which is their phone number.
 *
 * EVERY ACTION HERE IS AUDITED AND EVERY ONE DEMANDS A REASON. That is enforced
 * in WalletOpsService rather than here, so it cannot be bypassed by a second
 * caller. These endpoints only translate HTTP into those calls.
 *
 * NO BALANCE IS EVER SET DIRECTLY. The only way to change one is an adjustment,
 * which is a movement on the ledger with an actor and a reason attached --
 * because a balance that jumped with no movement explaining it is exactly what
 * makes a float unauditable.
 */
class WalletAdminController extends Controller
{
    use PaginatesResults;

    public function __construct(
        private WalletOpsService $ops,
        private WalletSettlementService $settlements,
    ) {}

    /**
     * Find a wallet
     *
     * By phone (what the passenger knows), account number (what they typed into
     * the paybill), or user id.
     *
     * @authenticated
     */
    public function show(Request $request): JsonResponse
    {
        $wallet = $this->resolve($request);

        if ($wallet === null) {
            return response()->json(['error' => 'No wallet found for that passenger.', 'code' => 'not_found'], 404);
        }

        $user = User::withoutGlobalScopes()->find($wallet->user_id);

        return response()->json(['wallet' => [
            'id' => $wallet->id,
            'user' => [
                'id' => (int) $wallet->user_id,
                'name' => trim(($user->firstname ?? '').' '.($user->lastname ?? '')),
                'phone' => $user->phone ?? null,
            ],
            'balance' => $wallet->balanceShillings(),
            'status' => $wallet->status->value,
            'frozen_at' => $wallet->frozen_at,
            'frozen_reason' => $wallet->frozen_reason,
            'pin_set' => $wallet->hasPin(),
            'tap_limit' => $wallet->tapLimitShillings(),
            'account_no' => $wallet->account_no,
            // Which bank is holding this passenger's money. Null means it
            // cannot be settled to anyone, which is a problem worth seeing.
            'float_bank' => $wallet->float_bank,
            'lifetime' => [
                'topped_up' => Shillings::toShillings((int) $this->kindSum($wallet->id, WalletEntryKind::TopUp)),
                'spent' => Shillings::toShillings(abs((int) $this->kindSum($wallet->id, WalletEntryKind::Fare))),
                'refunded' => Shillings::toShillings((int) $this->kindSum($wallet->id, WalletEntryKind::Refund)),
                'adjusted' => Shillings::toShillings((int) $this->kindSum($wallet->id, WalletEntryKind::Adjustment)),
            ],
        ]]);
    }

    /**
     * One wallet's ledger
     *
     * In order, with the running balance, so a support person can walk a
     * passenger through exactly what happened.
     *
     * @authenticated
     */
    public function ledger(Request $request): JsonResponse
    {
        $wallet = $this->resolve($request);

        if ($wallet === null) {
            return response()->json(['error' => 'No wallet found for that passenger.', 'code' => 'not_found'], 404);
        }

        $query = WalletTransaction::where('wallet_id', $wallet->id);
        $meta = $this->pageMeta($query, $request, 50);
        $page = max((int) $request->input('page', 1), 1);

        $rows = $query->orderByDesc('id')->skip(($page - 1) * 50)->take(50)->get();

        return response()->json(array_merge([
            'transactions' => $rows->map(fn (WalletTransaction $t) => [
                'id' => $t->id,
                'kind' => $t->kind->value,
                'direction' => $t->direction(),
                'amount' => $t->amountShillings(),
                'balance_after' => $t->balanceAfterShillings(),
                'title' => $t->title,
                'detail' => $t->detail,
                'reference' => $t->reference,
                'booking_id' => $t->booking_id,
                'vehicle_id' => $t->vehicle_id,
                'sacco_id' => $t->sacco_id,
                'mpesa_id' => $t->mpesa_id,
                // Whether this fare has been paid out to the SACCO yet -- the
                // thing that decides whether a refund needs a clawback.
                'settlement_id' => $t->wallet_settlement_id,
                'actor_id' => $t->actor_id,
                'meta' => $t->meta,
                'created_at' => $t->created_at,
            ])->values(),
            'top_ups' => WalletTopUp::where('wallet_id', $wallet->id)
                ->orderByDesc('id')->limit(20)->get()
                ->map(fn (WalletTopUp $t) => $t->toWire())->values(),
        ], $meta));
    }

    /**
     * Hold a wallet
     *
     * It can still RECEIVE while frozen -- a top-up already paid for, or a
     * refund owed, must still land. It cannot pay.
     *
     * @authenticated
     */
    public function freeze(Request $request): JsonResponse
    {
        return $this->acting($request, fn (Wallet $wallet, string $reason) => [
            'wallet' => $this->ops->freeze((int) $wallet->user_id, $reason, (int) auth()->id())->status->value,
        ]);
    }

    /**
     * Release a held wallet
     *
     * @authenticated
     */
    public function unfreeze(Request $request): JsonResponse
    {
        return $this->acting($request, fn (Wallet $wallet, string $reason) => [
            'wallet' => $this->ops->unfreeze((int) $wallet->user_id, $reason, (int) auth()->id())->status->value,
        ]);
    }

    /**
     * Correct a balance
     *
     * A signed amount in shillings: positive credits, negative debits. There is
     * no "set to" -- an adjustment is a movement on the ledger so it can be
     * explained afterwards.
     *
     * @authenticated
     */
    public function adjust(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first(), 'code' => 'invalid_request'], 422);
        }

        $wallet = $this->resolve($request);

        if ($wallet === null) {
            return response()->json(['error' => 'No wallet found for that passenger.', 'code' => 'not_found'], 404);
        }

        try {
            $entry = $this->ops->adjust(
                (int) $wallet->user_id,
                (int) $request->input('amount'),
                (string) $request->input('reason'),
                (int) auth()->id(),
            );
        } catch (WalletPaymentRefused $refused) {
            return response()->json(['error' => $refused->getMessage(), 'code' => 'refused'], $refused->status);
        } catch (WalletRefusal $refusal) {
            return response()->json($refusal->toArray(), $refusal->status);
        }

        return response()->json(['adjustment' => [
            'id' => $entry->id,
            'amount' => $entry->isCredit() ? $entry->amountShillings() : -$entry->amountShillings(),
            'balance_after' => $entry->balanceAfterShillings(),
        ]]);
    }

    /**
     * Refund a fare
     *
     * Always refunds the passenger. Whether the SACCO has already been PAID for
     * that ride decides what else happens, and the response says which:
     * `needs_clawback: true` means the settlement was already paid, nothing was
     * adjusted (a paid settlement is immutable), and finance has to recover it.
     *
     * @authenticated
     */
    public function refund(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'fare_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first(), 'code' => 'invalid_request'], 422);
        }

        try {
            $result = $this->ops->refundFare(
                (int) $request->input('fare_id'),
                (string) $request->input('reason'),
                (int) auth()->id(),
            );
        } catch (WalletPaymentRefused $refused) {
            return response()->json(['error' => $refused->getMessage(), 'code' => 'refused'], $refused->status);
        }

        return response()->json(['refund' => [
            'id' => $result['entry']->id,
            'amount' => $result['entry']->amountShillings(),
            'balance_after' => $result['entry']->balanceAfterShillings(),
            'needs_clawback' => $result['needs_clawback'],
            'settlement_id' => $result['settlement']?->id,
            'note' => $result['needs_clawback']
                ? 'The SACCO was already paid for this ride. Recover it manually.'
                : null,
        ]]);
    }

    /**
     * Agree a settlement figure
     *
     * Payable only from here. Deliberately a separate act from accrual: the
     * figures come from a sweep over somebody else's money and the first run
     * after a bug must not be able to pay out before a human has read it.
     *
     * @authenticated
     */
    public function approveSettlement(int $id): JsonResponse
    {
        $settlement = WalletSettlement::with('sacco:id,name')->findOrFail($id);

        try {
            $this->settlements->approve($settlement, (int) auth()->id());
        } catch (WalletPaymentRefused $refused) {
            return response()->json(['error' => $refused->getMessage(), 'code' => 'refused'], $refused->status);
        }

        return response()->json(['settlement' => $settlement->fresh()->toWire()]);
    }

    /**
     * Record that a settlement has been paid
     *
     * A payout reference is MANDATORY -- a bank transfer id, or whatever the
     * rail gave back. "Paid" with nothing to point at is an assertion nobody can
     * check, and this row is the only record a SACCO was settled for that day.
     *
     * @authenticated
     */
    public function paySettlement(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payout_reference' => ['required', 'string', 'min:3', 'max:190'],
            'rail' => ['nullable', 'string', 'max:24'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first(), 'code' => 'invalid_request'], 422);
        }

        $settlement = WalletSettlement::with('sacco:id,name')->findOrFail($id);

        try {
            $this->settlements->markPaid(
                $settlement,
                (string) $request->input('payout_reference'),
                // Defaults to manual because that is how it is actually paid
                // today: NCBA has no payout API at all.
                (string) ($request->input('rail') ?: 'manual'),
                (int) auth()->id(),
            );
        } catch (WalletPaymentRefused $refused) {
            return response()->json(['error' => $refused->getMessage(), 'code' => 'refused'], $refused->status);
        }

        return response()->json(['settlement' => $settlement->fresh()->toWire()]);
    }

    /**
     * Run the freeze/unfreeze shape: resolve, require a reason, act.
     *
     * @param  \Closure(Wallet, string): array<string, mixed>  $act
     */
    private function acting(Request $request, \Closure $act): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first(), 'code' => 'invalid_request'], 422);
        }

        $wallet = $this->resolve($request);

        if ($wallet === null) {
            return response()->json(['error' => 'No wallet found for that passenger.', 'code' => 'not_found'], 404);
        }

        try {
            return response()->json($act($wallet, (string) $request->input('reason')));
        } catch (WalletPaymentRefused $refused) {
            return response()->json(['error' => $refused->getMessage(), 'code' => 'refused'], $refused->status);
        }
    }

    /**
     * Find the wallet this request is about.
     *
     * Phone first, because it is the only identifier a passenger on the line
     * actually knows. Routed through App\Support\Phone so 0712..., 254712...
     * and +254712... all find the same person -- the normalisation
     * DriverAuthController still lacks.
     */
    private function resolve(Request $request): ?Wallet
    {
        if ($request->filled('account_no')) {
            return WalletAccountNumber::resolve((string) $request->input('account_no'));
        }

        if ($request->filled('user_id')) {
            return Wallet::where('user_id', (int) $request->input('user_id'))->first();
        }

        if ($request->filled('phone')) {
            $forms = Phone::lookupForms((string) $request->input('phone'));
            $userId = User::withoutGlobalScopes()->whereIn('phone', $forms)->value('id');

            return $userId === null ? null : Wallet::where('user_id', $userId)->first();
        }

        return null;
    }

    private function kindSum(int $walletId, WalletEntryKind $kind): int
    {
        return (int) WalletTransaction::where('wallet_id', $walletId)->where('kind', $kind->value)->sum('amount_cents');
    }
}
