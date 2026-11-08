<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Passenger;

use App\Enums\WalletTopUpRail;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletRefusal;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTopUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * A passenger's own wallet: balance, ledger, PIN and tap limit.
 *
 * Everything here is keyed on auth()->id() and nothing takes a user id from the
 * request. There is no permission branch either: a passenger holds no
 * permissions, so a gated route would return an empty screen — the trap
 * PassengerPaymentsController and CarbonCreditsController both exist to avoid.
 *
 * NO MONEY MOVES THROUGH THIS CONTROLLER YET. Top-ups and payments are phase 2
 * and 3; WalletService already has credit() and debit() under a row lock, but
 * nothing here calls them. That is deliberate: the balance, the ledger and the
 * PIN can be shipped and exercised against the real app while the rails that
 * move money are still waiting on credentials.
 *
 * Every refusal is rendered from WalletRefusal, so the body always carries the
 * `code` the app's wallet screens switch on. Returning a bare message here
 * would leave the client unable to tell "wrong PIN" from "locked out" and it
 * would show the wrong copy for both.
 */
class WalletController extends Controller
{
    use PaginatesResults;

    private const PER_PAGE = 20;

    public function __construct(
        private WalletService $wallet,
        private WalletTopUpService $topUps,
    ) {
        $this->middleware('auth:sanctum');
    }

    /**
     * The passenger's wallet
     *
     * Balance, status, limits and this month's fares. Reports `not_activated`
     * without creating a row for a passenger who has never used the wallet.
     *
     * @authenticated
     */
    public function show(): JsonResponse
    {
        return response()->json(['wallet' => $this->wallet->summary((int) auth()->id())]);
    }

    /**
     * The wallet ledger
     *
     * Newest first. `direction` carries the sign; `amount` is always positive.
     *
     * @authenticated
     */
    public function transactions(Request $request): JsonResponse
    {
        $ledger = $this->wallet->ledgerQuery((int) auth()->id());

        // Meta BEFORE skip/take, per the note on PaginatesResults — otherwise
        // the total reflects the page rather than the result set.
        $meta = $this->pageMeta($ledger, $request, self::PER_PAGE);

        $page = max((int) $request->input('page', 1), 1);

        $rows = $ledger
            ->orderByDesc('id')
            ->skip(($page - 1) * self::PER_PAGE)
            ->take(self::PER_PAGE)
            ->get()
            ->map(fn (WalletTransaction $entry) => [
                'id' => $entry->id,
                'kind' => $entry->kind->value,
                'direction' => $entry->direction(),
                'amount' => $entry->amountShillings(),
                'balance_after' => $entry->balanceAfterShillings(),
                'title' => $entry->title,
                'detail' => $entry->detail,
                'reference' => $entry->reference,
                'created_at' => $entry->created_at,
            ]);

        return response()->json(array_merge(['transactions' => $rows], $meta));
    }

    /**
     * Set or change the wallet PIN
     *
     * Required above the tap limit. Changing an existing PIN requires the
     * current one, and a wrong answer counts toward the lockout.
     *
     * @authenticated
     */
    public function setPin(Request $request): JsonResponse
    {
        $length = (int) config('wallet.pin.length');

        $validator = Validator::make($request->all(), [
            // digits: rather than a size rule, so "0012" survives — a numeric
            // rule would let 12 through as a four-digit PIN.
            'pin' => ['required', 'string', 'digits:'.$length],
            'current_pin' => ['nullable', 'string', 'digits:'.$length],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->first(),
                'code' => 'invalid_pin',
            ], 422);
        }

        return $this->answering(fn () => $this->wallet->setPin(
            (int) auth()->id(),
            (string) $request->input('pin'),
            $request->filled('current_pin') ? (string) $request->input('current_pin') : null,
        ));
    }

    /**
     * Change the no-PIN tap limit
     *
     * PATCH, and the PIN is required — the limit is the only thing between a
     * stolen unlocked phone and the whole balance, so raising it costs the same
     * secret as spending above it.
     *
     * @authenticated
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $max = (int) config('wallet.limits.max_tap_limit');
        $length = (int) config('wallet.pin.length');

        $validator = Validator::make($request->all(), [
            'tap_limit' => ['required', 'integer', 'min:0', 'max:'.$max],
            'pin' => ['required', 'string', 'digits:'.$length],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->first(),
                'code' => 'invalid_tap_limit',
                'max_tap_limit' => $max,
            ], 422);
        }

        return $this->answering(fn () => $this->wallet->setTapLimit(
            (int) auth()->id(),
            (int) $request->input('tap_limit'),
            (string) $request->input('pin'),
        ));
    }

    /**
     * Start a top-up
     *
     * Creates the attempt and asks the rail to prompt the handset. The money
     * does NOT arrive through this call -- it arrives as an M-Pesa confirmation,
     * so a 200 here means "a prompt was sent", never "you have been credited".
     * Poll GET /wallet/top-ups/{id} for the outcome.
     *
     * @authenticated
     */
    public function topUp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'integer', 'min:1'],
            'source' => ['required', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->first(),
                'code' => 'invalid_request',
            ], 422);
        }

        $rail = WalletTopUpRail::tryParse((string) $request->input('source'));

        if ($rail === null) {
            return response()->json([
                'error' => 'Choose a top-up method.',
                'code' => 'invalid_source',
            ], 422);
        }

        try {
            $attempt = $this->topUps->start(
                (int) auth()->id(),
                (int) $request->input('amount'),
                $rail,
                $request->filled('phone') ? (string) $request->input('phone') : null,
                $this->idempotencyKey($request),
            );
        } catch (WalletRefusal $refusal) {
            return response()->json($refusal->toArray(), $refusal->status);
        }

        return response()->json(['top_up' => $attempt->toWire()]);
    }

    /**
     * Poll a top-up
     *
     * `status` is `pending` until the money lands. Anything else is final
     * except `timed_out`, which may still settle if a late payment arrives.
     *
     * @authenticated
     */
    public function topUpStatus(int $id): JsonResponse
    {
        $attempt = $this->topUps->find((int) auth()->id(), $id);

        if ($attempt === null) {
            // Scoped to the caller's own wallet, so a foreign id is a 404 and
            // not a 403: confirming that someone else's top-up exists would
            // leak that it does.
            return response()->json(['error' => 'No such top-up.', 'code' => 'not_found'], 404);
        }

        return response()->json(['top_up' => $attempt->toWire()]);
    }

    /**
     * The idempotency key for this attempt.
     *
     * The app mints one and sends it as a header (core/network/idempotency.dart),
     * which is what makes a double-tapped Top Up button produce one prompt. A
     * client that sends none gets a key derived from the request instead, so
     * two identical taps within the same minute still collapse -- weaker than
     * a real key, but far better than prompting twice for the same money.
     */
    private function idempotencyKey(Request $request): string
    {
        foreach (['Idempotency-Key', 'X-Idempotency-Key'] as $header) {
            $value = trim((string) $request->header($header, ''));

            if ($value !== '') {
                return mb_substr($value, 0, 120);
            }
        }

        return 'derived:'.sha1(implode('|', [
            (string) auth()->id(),
            (string) $request->input('amount'),
            (string) $request->input('source'),
            Carbon::now()->format('YmdHi'),
        ]));
    }

    /**
     * Run a wallet write and answer with the wallet, or with its refusal.
     *
     * The summary is re-read AFTER the write rather than built from the
     * returned model, so the body the app patches its screen from is the same
     * shape GET /wallet returns — including `this_month`, which the model does
     * not carry. A client that got a different shape from a PATCH than from a
     * GET would need two parsers for one object.
     *
     * @param  \Closure():mixed  $write
     */
    private function answering(\Closure $write): JsonResponse
    {
        try {
            $write();
        } catch (WalletRefusal $refusal) {
            return response()->json($refusal->toArray(), $refusal->status);
        } catch (InvalidArgumentException $e) {
            // A caller-side mistake the validator did not already cover.
            return response()->json(['error' => $e->getMessage(), 'code' => 'invalid_request'], 422);
        }

        return response()->json(['wallet' => $this->wallet->summary((int) auth()->id())]);
    }
}
