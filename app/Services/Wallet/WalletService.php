<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Enums\BankPartner;
use App\Enums\WalletEntryKind;
use App\Enums\WalletStatus;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\BusinessDay;
use App\Support\Shillings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * Every movement of passenger money goes through here.
 *
 * THE CONCURRENCY GUARD IS THE ROW LOCK, exactly as in LoyaltyService: the
 * wallet row is locked with lockForUpdate inside a transaction and the balance
 * is read, checked and written under that lock. Nothing in this class reads a
 * balance outside the lock and then decides on it, because a check outside the
 * lock can be stale before the write lands -- which on a money column means
 * two taps against a KES 50 balance can both see 50 and both succeed.
 *
 * AMOUNTS IN ARE ALWAYS POSITIVE. credit() and debit() apply the sign
 * themselves. Letting callers pass a signed value would mean one dropped minus
 * turns a fare into a free top-up, and that is not a mistake a test reliably
 * catches.
 *
 * EVERY WRITE NEEDS A `reference`, and a repeat of one replays the stored row
 * rather than moving money again. Phone networks here retry, STK callbacks
 * arrive more than once, and a passenger on a moving matatu taps twice. The
 * reference is what makes all three harmless.
 */
final class WalletService
{
    /**
     * The passenger-facing summary -- the body of GET /wallet.
     *
     * A PURE READ: it must not create a wallet row. A passenger who has never
     * used the wallet gets a synthetic `not_activated` view, which is exactly
     * what the app's own WalletStatus.fromApi falls back to. Creating a row on
     * a GET would make every passenger who merely opened the screen into a
     * float-bearing account in the reconciliation sweep.
     *
     * @return array<string, mixed>
     */
    public function summary(int $userId): array
    {
        $wallet = Wallet::where('user_id', $userId)->first();
        $limits = (array) config('wallet.limits');

        return [
            'balance' => $wallet?->balanceShillings() ?? 0,
            'currency' => 'KES',
            'status' => ($wallet?->status ?? WalletStatus::NotActivated)->value,
            'pin_set' => (bool) $wallet?->hasPin(),
            'tap_limit' => $wallet?->tapLimitShillings() ?? (int) config('wallet.default_tap_limit'),
            'limits' => [
                'min_top_up' => (int) $limits['min_top_up'],
                'max_top_up' => (int) $limits['max_top_up'],
                'max_balance' => (int) $limits['max_balance'],
                'max_tap_limit' => (int) $limits['max_tap_limit'],
            ],
            'top_up_sources' => array_values(array_map(
                fn (array $source) => [
                    'id' => (string) $source['id'],
                    'available' => (bool) $source['available'],
                ],
                (array) config('wallet.top_up_sources', []),
            )),
            'this_month' => $this->fareTotalsThisMonth($wallet?->id),
        ];
    }

    /**
     * Fares taken this month, as the app's `this_month` block.
     *
     * The month starts at the 03:00-EAT business boundary via BusinessDay, not
     * at UTC midnight, so a passenger's monthly figure lines up with the
     * operator's month on every other money screen (DriverPortalController
     * computes its month the same way). `config('app.timezone')` is UTC here,
     * so taking the naive month would shift the boundary three hours and
     * disagree with the takings reports by whatever landed in between.
     *
     * @return array{spent: int, fares: int}
     */
    private function fareTotalsThisMonth(?int $walletId): array
    {
        if ($walletId === null) {
            return ['spent' => 0, 'fares' => 0];
        }

        [$from] = BusinessDay::windowFor(Carbon::now()->startOfMonth());

        $row = WalletTransaction::query()
            ->where('wallet_id', $walletId)
            ->where('kind', WalletEntryKind::Fare->value)
            ->where('created_at', '>=', $from)
            ->selectRaw('coalesce(sum(abs(amount_cents)), 0) as spent_cents, count(*) as fare_count')
            ->first();

        return [
            'spent' => Shillings::toShillings((int) ($row->spent_cents ?? 0)),
            'fares' => (int) ($row->fare_count ?? 0),
        ];
    }

    /**
     * The passenger's own ledger as a query, newest first.
     *
     * Returns the BUILDER rather than a paginator so the controller can use
     * PaginatesResults::pageMeta, which is how every other listing here pages
     * and which already emits the `current_page` / `last_page` the app's Paged
     * reads. Laravel's own paginator would nest the rows under `data` and the
     * client would find none.
     *
     * A passenger with no wallet yet gets an empty result rather than a 404:
     * the screen is reachable before the wallet exists, and an error there
     * reads to the passenger as money having gone missing.
     *
     * @return Builder<WalletTransaction>
     */
    public function ledgerQuery(int $userId): Builder
    {
        $walletId = Wallet::where('user_id', $userId)->value('id');

        return WalletTransaction::query()->where('wallet_id', $walletId ?? 0);
    }

    /**
     * Lock this passenger's wallet row, creating it if this is the first touch.
     *
     * MUST BE CALLED INSIDE A TRANSACTION. Lifted from
     * LoyaltyService::lockAccount, including the reason for its shape:
     * firstOrCreate is savepoint-safe inside a transaction (Eloquent's
     * createOrFirst), so two first-ever movements racing to create the same
     * wallet do not poison the loser's transaction -- it finds the winner's row
     * and locks that one. A refused movement rolls the new row back with
     * everything else.
     */
    public function lock(int $userId): Wallet
    {
        Wallet::firstOrCreate(
            ['user_id' => $userId],
            [
                'balance_cents' => 0,
                'status' => WalletStatus::Active,
                'tap_limit_cents' => Shillings::toCents((int) config('wallet.default_tap_limit')),
                'float_bank' => $this->floatBank(),
            ],
        );

        return Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Which bank holds this passenger's float, from the brand on the request.
     *
     * BankPartner::forBrand throws for an unmapped brand -- correctly, since
     * routing a new white-label's money to whichever bank happened to be the
     * fallback is not recoverable by an apology. But a wallet that cannot be
     * CREATED is worse than one whose float bank is unknown: the first loses
     * the passenger their money, the second is a reconciliation question the
     * sweep can raise. So this degrades to null and the row is written anyway.
     */
    private function floatBank(): ?string
    {
        try {
            $brand = Context::has('brand') ? (string) Context::get('brand') : 'komiut';

            return BankPartner::forBrand($brand)->value;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Put money in.
     *
     * Allowed on a FROZEN wallet on purpose: a hold stops a passenger
     * spending, but a top-up they have already paid for, or a refund they are
     * owed, must still land -- refusing it strands real money outside the
     * ledger with nothing to reconcile it against.
     *
     * @param  array<string, mixed>  $attrs  title, detail, booking_id, vehicle_id, sacco_id, financier, mpesa_id, actor_id, meta
     */
    public function credit(int $userId, int $amountCents, WalletEntryKind $kind, string $reference, array $attrs = []): WalletTransaction
    {
        return $this->move($userId, abs($amountCents), $kind, $reference, $attrs, credit: true);
    }

    /**
     * Take money out. Refuses a frozen wallet and refuses an overdraw.
     *
     * @param  array<string, mixed>  $attrs  as credit()
     */
    public function debit(int $userId, int $amountCents, WalletEntryKind $kind, string $reference, array $attrs = []): WalletTransaction
    {
        return $this->move($userId, abs($amountCents), $kind, $reference, $attrs, credit: false);
    }

    /** @param array<string, mixed> $attrs */
    private function move(
        int $userId,
        int $amountCents,
        WalletEntryKind $kind,
        string $reference,
        array $attrs,
        bool $credit,
    ): WalletTransaction {
        if ($amountCents <= 0) {
            // The CHECK constraint would catch this too, but a clear exception
            // at the call site beats a database error surfacing three layers up.
            throw new InvalidArgumentException('A wallet movement must be a non-zero amount.');
        }

        return DB::transaction(function () use ($userId, $amountCents, $kind, $reference, $attrs, $credit) {
            $wallet = $this->lock($userId);

            // THE REPLAY CHECK, INSIDE THE LOCK. Outside it, two concurrent
            // retries of one reference would both miss and both insert: one
            // would die on the unique index, but only after the other had
            // already moved the money a second time.
            $existing = WalletTransaction::where('reference', $reference)->first();

            if ($existing !== null) {
                if ((int) $existing->wallet_id !== (int) $wallet->id) {
                    // References are globally unique, so this means two callers
                    // minted the same key for different passengers. Returning
                    // the stored row would hand one passenger another's
                    // movement, so it has to fail instead.
                    throw new InvalidArgumentException(
                        "Wallet reference [{$reference}] already belongs to another wallet."
                    );
                }

                return $existing;
            }

            if (! $credit && ! $wallet->canPay()) {
                throw WalletRefusal::frozen();
            }

            $delta = $credit ? $amountCents : -$amountCents;
            $after = (int) $wallet->balance_cents + $delta;

            if ($after < 0) {
                throw WalletRefusal::insufficientBalance((int) $wallet->balance_cents);
            }

            if ($credit) {
                $maxShillings = (int) config('wallet.limits.max_balance');

                if ($after > Shillings::toCents($maxShillings)) {
                    throw WalletRefusal::wouldExceedMaxBalance($maxShillings);
                }
            }

            $wallet->balance_cents = $after;
            $wallet->save();

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'kind' => $kind,
                'amount_cents' => $delta,
                'balance_after_cents' => $after,
                'title' => (string) ($attrs['title'] ?? $kind->label()),
                'detail' => $attrs['detail'] ?? null,
                'reference' => $reference,
                'booking_id' => $attrs['booking_id'] ?? null,
                'vehicle_id' => $attrs['vehicle_id'] ?? null,
                'sacco_id' => $attrs['sacco_id'] ?? null,
                'financier' => $attrs['financier'] ?? null,
                'mpesa_id' => $attrs['mpesa_id'] ?? null,
                'actor_id' => $attrs['actor_id'] ?? null,
                'meta' => $attrs['meta'] ?? null,
            ]);
        });
    }

    public function balanceCents(int $userId): int
    {
        return (int) (Wallet::where('user_id', $userId)->value('balance_cents') ?? 0);
    }

    /**
     * Set or change the PIN. Setting one is what activates a wallet.
     *
     * Changing an existing PIN requires the current one, and a wrong answer
     * counts toward the same lockout a payment does -- otherwise this endpoint
     * is an unlimited oracle for guessing the PIN that protects payments.
     *
     * The current PIN is checked BEFORE the write transaction opens. There is a
     * hair-thin window in which the PIN could change in between, which is
     * acceptable here because both requests are the same passenger acting on
     * their own wallet; checking inside the transaction instead would silently
     * break the lockout (see assertPinMatches).
     */
    public function setPin(int $userId, string $pin, ?string $currentPin): Wallet
    {
        if ($this->isWeakPin($pin)) {
            throw WalletRefusal::weakPin();
        }

        $existing = Wallet::where('user_id', $userId)->first();

        if ($existing !== null && $existing->hasPin()) {
            $this->assertPinMatches($existing, $currentPin);
        }

        return DB::transaction(function () use ($userId, $pin) {
            $wallet = $this->lock($userId);

            $wallet->pin_hash = Hash::make($pin);

            // Setting a PIN is the activation step.
            if ($wallet->status === WalletStatus::NotActivated) {
                $wallet->status = WalletStatus::Active;
            }

            $wallet->save();

            return $wallet;
        });
    }

    /**
     * Raise or lower the no-PIN tap ceiling. ALWAYS requires the PIN.
     *
     * The tap limit is the only thing between a stolen unlocked phone and the
     * whole balance, so changing it must cost the same secret as spending above
     * it. A wallet with no PIN cannot raise its ceiling at all.
     */
    public function setTapLimit(int $userId, int $shillings, ?string $pin): Wallet
    {
        $max = (int) config('wallet.limits.max_tap_limit');

        if ($shillings < 0 || $shillings > $max) {
            throw new InvalidArgumentException("A tap limit must be between 0 and {$max}.");
        }

        $existing = Wallet::where('user_id', $userId)->first();

        if ($existing === null || ! $existing->hasPin()) {
            throw WalletRefusal::noPinSet();
        }

        $this->assertPinMatches($existing, $pin);

        return DB::transaction(function () use ($userId, $shillings) {
            $wallet = $this->lock($userId);
            $wallet->tap_limit_cents = Shillings::toCents($shillings);
            $wallet->save();

            return $wallet;
        });
    }

    /**
     * Demand the PIN for an amount that needs one, and check it.
     *
     * CALL THIS BEFORE OPENING THE MONEY TRANSACTION, not inside it -- see
     * assertPinMatches for why a refusal inside a transaction cannot be counted.
     *
     * Verifying outside the lock is safe because a PIN is an authorisation
     * check, not a balance check: the only thing racing many correct-PIN
     * requests can achieve is spending a balance that is still re-read and
     * re-checked under the lock in move().
     */
    public function assertPinFor(Wallet $wallet, ?string $pin, int $amountCents): void
    {
        if (! $wallet->needsPin($amountCents)) {
            return;
        }

        if (! $wallet->hasPin()) {
            throw WalletRefusal::noPinSet();
        }

        if ($pin === null || $pin === '') {
            throw WalletRefusal::pinRequired((int) $wallet->tap_limit_cents);
        }

        $this->assertPinMatches($wallet, $pin);
    }

    /** The limiter key is per passenger, so attempts follow the wallet, not the device. */
    private function pinKey(int $userId): string
    {
        return 'wallet:pin:'.$userId;
    }

    /**
     * Check a PIN and count a wrong one. Throws on anything but a match.
     *
     * THE COUNTER LIVES IN THE RATE LIMITER, NOT THE DATABASE, and this is the
     * load-bearing decision. Every PIN check precedes a refusal that unwinds
     * the request, and in Postgres a counter written on the same connection
     * inside that transaction rolls back along with the refusal -- so the
     * lockout would forget every failure and a 4-digit PIN (10,000
     * combinations) would be brute-forceable as fast as the API answers. The
     * limiter writes to the cache, which no database rollback can touch.
     *
     * A correct PIN clears the key, so four wrong tries spread over a week do
     * not lock a passenger out on their next genuine typo.
     */
    private function assertPinMatches(Wallet $wallet, ?string $pin): void
    {
        $key = $this->pinKey((int) $wallet->user_id);
        $max = (int) config('wallet.pin.max_attempts');
        $lockSeconds = (int) config('wallet.pin.lock_minutes') * 60;

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw WalletRefusal::pinLocked($this->lockedUntil($key));
        }

        if ($pin === null || $pin === '' || ! Hash::check($pin, (string) $wallet->pin_hash)) {
            RateLimiter::hit($key, $lockSeconds);

            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw WalletRefusal::pinLocked($this->lockedUntil($key));
            }

            throw WalletRefusal::wrongPin(RateLimiter::remaining($key, $max));
        }

        RateLimiter::clear($key);
    }

    private function lockedUntil(string $key): Carbon
    {
        return Carbon::now()->addSeconds(max(1, RateLimiter::availableIn($key)));
    }

    /**
     * The PINs that are not worth having.
     *
     * Four digits is 10,000 combinations and a large share of people pick from
     * a handful of them, so refusing the obvious ones is most of the value a
     * PIN policy can add at this length.
     */
    private function isWeakPin(string $pin): bool
    {
        $length = (int) config('wallet.pin.length');

        if (preg_match('/^\d{'.$length.'}$/', $pin) !== 1) {
            return true;
        }

        if (count(array_unique(str_split($pin))) === 1) {
            return true;
        }

        $ascending = '0123456789';

        return str_contains($ascending, $pin) || str_contains(strrev($ascending), $pin);
    }
}
