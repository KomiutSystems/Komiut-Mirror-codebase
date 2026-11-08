<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Enums\WalletTopUpRail;
use App\Support\Shillings;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A wallet operation the passenger is not allowed to complete.
 *
 * ONE EXCEPTION WITH A CODE, rather than a class per failure, because the wire
 * contract is a code: the app's wallet screens switch on `code` and render
 * their own copy for `insufficient_balance`, `pin_required`, `wrong_pin` and
 * `pin_locked` (see walletRefusalExamples in wallet_data.dart). Modelling those
 * as separate PHP classes would add a mapping layer whose only job is to
 * collapse them back into the string the client actually reads.
 *
 * The named constructors below ARE the contract. Each carries the extra field
 * the app needs to render its message -- `attempts_left`, `locked_until`,
 * `balance` -- because without them the client has to guess, and a wallet
 * screen that guesses tells the passenger the wrong thing about their money.
 *
 * 422 throughout, matching LoyaltyService, which returns 422 for every
 * equivalent refusal. These are not failures of the request's shape (422 is
 * arguable there) but they ARE how the existing money paths answer, and one
 * vocabulary across both rails is worth more than purity.
 */
final class WalletRefusal extends RuntimeException
{
    /** @param array<string, mixed> $extra */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $extra = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function insufficientBalance(int $balanceCents): self
    {
        $balance = Shillings::toShillings($balanceCents);

        return new self(
            'insufficient_balance',
            "Your wallet has KES {$balance}. Top up or pay with M-Pesa.",
            ['balance' => $balance],
        );
    }

    public static function pinRequired(int $tapLimitCents): self
    {
        $limit = Shillings::toShillings($tapLimitCents);

        return new self('pin_required', "Enter your wallet PIN for fares above KES {$limit}.");
    }

    public static function wrongPin(int $attemptsLeft): self
    {
        $tries = $attemptsLeft === 1 ? '1 try left.' : "{$attemptsLeft} tries left.";

        return new self('wrong_pin', "That PIN is wrong. {$tries}", ['attempts_left' => $attemptsLeft]);
    }

    public static function pinLocked(Carbon $until): self
    {
        return new self(
            'pin_locked',
            'Too many wrong PINs. Try again at '.$until->copy()->setTimezone('Africa/Nairobi')->format('H:i').'.',
            ['locked_until' => $until->toIso8601String()],
        );
    }

    public static function noPinSet(): self
    {
        return new self('pin_required', 'Set a wallet PIN before paying this much.');
    }

    public static function frozen(): self
    {
        return new self('wallet_frozen', 'This wallet is on hold. Contact support.');
    }

    public static function wouldExceedMaxBalance(int $maxBalanceShillings): self
    {
        return new self(
            'max_balance',
            "A wallet cannot hold more than KES {$maxBalanceShillings}.",
            ['max_balance' => $maxBalanceShillings],
        );
    }

    public static function weakPin(): self
    {
        return new self('weak_pin', 'Pick a less obvious PIN — not 0000, 1234 or four of the same digit.');
    }

    public static function topUpOutOfRange(int $minShillings, int $maxShillings): self
    {
        return new self(
            'amount_out_of_range',
            "Top up between KES {$minShillings} and KES {$maxShillings}.",
            ['min_top_up' => $minShillings, 'max_top_up' => $maxShillings],
        );
    }

    /**
     * No rail can take money right now.
     *
     * Says so plainly rather than pretending to prompt. The float account is
     * not provisioned (see WalletFloat), and a spinner that never resolves is
     * worse for a passenger than being told the feature is off.
     */
    public static function railUnavailable(WalletTopUpRail $rail): self
    {
        return new self(
            'source_unavailable',
            match ($rail) {
                WalletTopUpRail::Mpesa => 'M-Pesa top-ups are not switched on yet.',
                WalletTopUpRail::Ncba => 'NCBA top-ups are not switched on yet.',
            },
            ['source' => $rail->value],
        );
    }

    public static function noPhoneToPrompt(): self
    {
        return new self('phone_required', 'Add a phone number to top up.');
    }

    /**
     * The same idempotency key arrived for two different passengers.
     *
     * Returning the first passenger's attempt would show one person another's
     * top-up and its amount, so this fails instead.
     */
    public static function foreignIdempotencyKey(): self
    {
        return new self('invalid_request', 'That top-up reference belongs to another account.');
    }

    /** The body the app parses. `error` first: every client here reads that key. */
    public function toArray(): array
    {
        return array_merge(['error' => $this->getMessage(), 'code' => $this->errorCode], $this->extra);
    }
}
