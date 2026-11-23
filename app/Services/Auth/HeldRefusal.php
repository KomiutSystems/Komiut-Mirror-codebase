<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * A sign-in refusal kept back until it is known to be final.
 *
 * mobile/login tries a plate-shaped secret as a plate first, and then as a
 * password. A crew member whose password happens to look like a plate fails
 * the first and passes the second; writing the first down would log a refusal
 * on a sign-in that succeeded. This stands in for LoginRefusals during the
 * plate attempt and is released only if the password fails too.
 */
final class HeldRefusal
{
    /** @var array{0: string, 1: string, 2: ?string, 3: ?string, 4: array<string, scalar|null>}|null */
    private ?array $held = null;

    /** @param  array<string, scalar|null>  $context */
    public function record(string $signIn, string $reason, ?string $phone, ?string $ip, array $context = []): void
    {
        $this->held = [$signIn, $reason, $phone, $ip, $context];
    }

    public function releaseTo(LoginRefusals $refusals): void
    {
        if ($this->held !== null) {
            $refusals->record(...$this->held);
            $this->held = null;
        }
    }
}
