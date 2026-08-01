<?php

declare(strict_types=1);

namespace App\Services\Mpesa;

use App\Models\Mpesa;
use App\Models\Transaction;

/**
 * The outcome of C2bPaymentRecorder::record() — enough for a caller to build its
 * own webhook response without reaching back into Eloquent state.
 */
final class C2bRecordResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly bool $wasDuplicate,
        public readonly ?string $error,
        public readonly ?Mpesa $mpesa,
        public readonly ?Transaction $transaction,
    ) {}

    public static function created(Mpesa $mpesa, Transaction $transaction): self
    {
        return new self(true, false, null, $mpesa, $transaction);
    }

    public static function duplicate(Mpesa $mpesa, Transaction $transaction): self
    {
        return new self(true, true, null, $mpesa, $transaction);
    }

    public static function failed(string $error): self
    {
        return new self(false, false, $error, null, null);
    }
}
