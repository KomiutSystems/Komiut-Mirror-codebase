<?php

declare(strict_types=1);

namespace App\Services\Coop;

/**
 * One answer from Co-op's STK API, read without trusting its shape.
 *
 * WHY THIS IS LENIENT ABOUT NAMES AND STRICT ABOUT MEANING. The bank gave us the
 * three REQUESTS (see CoopStkClient) and no sample response, callback or status
 * list. So fields are found by name wherever they sit, in any letter case, and
 * `Name`/`Value` pairs are read as fields too. But a payment is only called
 * PAID on evidence that cannot be mistaken for "your request was received":
 * an M-Pesa receipt. Anything else is "not decided yet" and is asked again --
 * a passenger left on "processing" is a support call; a ride marked paid on a
 * misread answer is a free ride.
 *
 * WHAT THE BANK REALLY SAYS (production, 2026-11-26):
 *   push accepted   {"MessageCode": "0", "MessageDescription": "REQUEST ACCEPTED FOR PROCESSING"}
 *   push refused    {"PayResponse": null}   -- an operator code it has not set up; no prompt
 *                   {"MessageCode": "E_001", "MessageDescription": "Invalid Operator Code"}
 *   enquiry         "-13 Message Reference does not exist" -> "S_001 PROCESSING" ->
 *                   "0 FULL SUCCESS", TransactionMetadata.Items = Amount "1.0",
 *                   PhoneNumber "2547...", Narration "<ours>~UKQ4340ZXN~2026-11-26"
 *                   "Service Backend timeout" -- the bank's backend, not the payment
 * No refused or cancelled payment has been seen yet: those are read by meaning,
 * and the raw body of every enquiry is kept on the receipt row to check against.
 */
final class CoopStkAnswer
{
    public const SUCCESS = 'success';

    /** The answer says paid but shows no receipt: not proof by itself. */
    public const CLAIMED = 'claimed';

    public const FAILED = 'failed';

    public const PENDING = 'pending';

    /** Fields that describe the PAYMENT, as opposed to the API call that asked about it. */
    private const TRANSACTION_STATUS_KEYS = ['status', 'transactionstatus', 'paymentstatus', 'transactionstate', 'stkstatus', 'state'];

    private const DESCRIPTION_KEYS = ['messagedescription', 'responsedescription', 'resultdesc', 'resultdescription', 'statusdescription', 'description', 'message'];

    private const CODE_KEYS = ['messagecode', 'responsecode', 'resultcode', 'statuscode'];

    /** @var list<array{0: string, 1: string}> every scalar in the body as [lower-cased key, value] */
    private array $pairs = [];

    /** @param  array<mixed>  $body */
    public function __construct(public readonly int $status, public readonly array $body)
    {
        $this->collect($body);
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300 && ! array_key_exists('fault', array_change_key_case($this->body, CASE_LOWER));
    }

    /** The first non-empty value filed under any of these names, at any depth. */
    public function value(string ...$names): ?string
    {
        $names = array_map('strtolower', $names);
        foreach ($this->pairs as [$key, $value]) {
            if ($value !== '' && in_array($key, $names, true)) {
                return $value;
            }
        }

        return null;
    }

    public function code(): ?string
    {
        return $this->value(...self::CODE_KEYS);
    }

    public function description(): ?string
    {
        return $this->value(...self::DESCRIPTION_KEYS);
    }

    /**
     * Did the bank take the push request? (The answer to FT/stk, not the payment.)
     *
     * Co-op's APIs acknowledge with MessageCode "0". A 2xx that names no code
     * at all is taken as accepted too: the prompt may be on the handset, and
     * the enquiry will say what became of it.
     */
    public function accepted(): bool
    {
        $code = $this->code();

        return $this->ok() && ! $this->refused() && ($code === null || in_array($code, ['0', '00', '200'], true));
    }

    /**
     * Did the bank plainly REFUSE the push request, so that no prompt went out?
     *
     * Needs positive evidence: a non-2xx, a gateway fault, or one of Co-op's
     * negative codes ("-2 INVALID ..."). A 2xx carrying a code we do not know
     * is neither accepted nor refused -- the prompt may well be on the handset
     * -- and is treated as unanswered, which is still asked about.
     */
    public function refused(): bool
    {
        if (! $this->ok() || preg_match('/^(-\d+|E_\d+)$/i', (string) $this->code()) === 1) {
            return true;
        }

        // {"PayResponse": null}: what the bank answers for an operator code it
        // has not set up. No prompt goes out, and an enquiry never finds it.
        return $this->code() === null && array_key_exists('PayResponse', $this->body) && $this->body['PayResponse'] === null;
    }

    /**
     * What became of the payment, from an enquiry answer.
     *
     * SUCCESS  an M-Pesa receipt, and no status field contradicting it.
     * CLAIMED  a status field that says exactly "paid"/"success"/"completed",
     *          but no receipt. Not proof on its own -- "Status: Success" is
     *          also how an API describes its own call -- so the settlement
     *          wants the bank's payment feed to show the money first.
     * FAILED   a status field that says so, or a description of something
     *          only a payer's handset does (cancelled, wrong PIN, no funds).
     *          Never from a general error message: "request timed out" about
     *          the enquiry itself must not close a prompt that can still be paid.
     * PENDING  everything else, including an answer that contradicts itself.
     */
    public function verdict(): string
    {
        $states = $this->values(self::TRANSACTION_STATUS_KEYS);
        $descriptions = $this->values(self::DESCRIPTION_KEYS);

        // "No such reference" straight after a push is the bank not having
        // filed it yet, not a refusal.
        if ($this->anyMatch(array_merge($states, $descriptions), '/not\s+found|no\s+record|does\s+not\s+exist/i')) {
            return self::PENDING;
        }

        $failed = $this->anyMatch($states, '/fail|cancel|declin|reject|insufficient|expired|tim(e|ed)?\s?out|wrong\s+pin|denied|reversed/i');
        $inFlight = $this->anyMatch($states, '/\b(pending|processing|initiated|queued|in[\s_-]?progress|awaiting|waiting|accepted|sent)\b/i');

        if ($this->receipt() !== null) {
            return $failed || $inFlight ? self::PENDING : self::SUCCESS;
        }

        if ($failed || $this->anyMatch($descriptions, '/cancell?ed\s+by\s+(the\s+)?(user|customer)|insufficient|wrong\s+pin|invalid\s+pin|ds\s+timeout|cannot\s+be\s+reached/i')) {
            return self::FAILED;
        }

        // Co-op files the payment's state in MessageDescription ("PROCESSING",
        // "FULL SUCCESS"), so a failure is read there too -- but only under a
        // code that is about the payment: "0"/"S_..." are progress, and
        // "Service" is the bank's own backend ("Backend timeout"), which says
        // nothing about whether the passenger paid. Only what a payer's handset
        // does counts: "ENQUIRY FAILED" is about our call, not the payment.
        $code = (string) $this->code();
        if ($code !== '' && $code !== 'Service' && ! in_array($code, ['0', '00'], true) && preg_match('/^S_/i', $code) !== 1
            && $this->anyMatch($descriptions, '/cancel|insufficient|declin|wrong\s+pin|invalid\s+pin|reversed/i')) {
            return self::FAILED;
        }

        // The whole value, not a word inside it: "Not Paid" is not "paid".
        if (! $inFlight && $this->anyMatch($states, '/^(transaction\s+|payment\s+)?(success(ful(ly)?)?|completed?|paid)$/i')) {
            return self::CLAIMED;
        }

        return self::PENDING;
    }

    /**
     * An M-Pesa receipt, wherever the bank filed it: ten capitals and digits,
     * starting with a letter and holding at least one digit (UK5431AUQI). The
     * shape matters -- "PROCESSING" and "0000000000" are ten characters too.
     */
    public function receipt(): ?string
    {
        foreach ($this->pairs as [$key, $value]) {
            // Co-op hands the receipt back inside the narration we sent:
            // "<ours>~UKQ4340ZXN~2026-11-26". Ours never holds a "~".
            if ($key === 'narration' && preg_match('/~((?=[A-Z0-9]*\d)[A-Z][A-Z0-9]{9})~/', strtoupper($value), $m)) {
                return $m[1];
            }
            if ((str_contains($key, 'receipt') || in_array($key, ['mpesaref', 'mpesareference', 'mpesatransactionid'], true))
                && preg_match('/^(?=.*\d)[A-Z][A-Z0-9]{9}$/', strtoupper($value))) {
                return strtoupper($value);
            }
        }

        return null;
    }

    /** The payer's number the bank says it charged, when it says. */
    public function phone(): ?string
    {
        return $this->value('phonenumber', 'msisdn', 'mobilenumber');
    }

    /** The amount the bank says was paid, when it says. */
    public function amount(): ?float
    {
        $amount = $this->value('amount', 'transactionamount', 'transamount', 'paidamount');

        return $amount !== null && is_numeric($amount) ? (float) $amount : null;
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function values(array $keys): array
    {
        $found = [];
        foreach ($this->pairs as [$key, $value]) {
            if ($value !== '' && in_array($key, $keys, true)) {
                $found[] = $value;
            }
        }

        return $found;
    }

    /** @param  list<string>  $values */
    private function anyMatch(array $values, string $pattern): bool
    {
        foreach ($values as $value) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<mixed>  $node */
    private function collect(array $node): void
    {
        $lower = array_change_key_case(array_filter($node, 'is_string', ARRAY_FILTER_USE_KEY), CASE_LOWER);
        // {"Name": "MpesaReceiptNumber", "Value": "UK5431AUQI"}: a field, spelt as a pair.
        if (isset($lower['name']) && is_scalar($lower['name']) && array_key_exists('value', $lower) && is_scalar($lower['value'])) {
            $this->pairs[] = [strtolower(trim((string) $lower['name'])), trim((string) $lower['value'])];
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $this->collect($value);
            } elseif (is_scalar($value) && is_string($key)) {
                $this->pairs[] = [strtolower($key), trim(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value)];
            }
        }
    }
}
