<?php

declare(strict_types=1);

namespace App\Brands\Exceptions;

use RuntimeException;

/**
 * Thrown when the current Brand is resolved from the container but no brand was
 * ever activated for this lifecycle.
 *
 * At the HTTP boundary an unknown host/app-key is a 404 (handled in the
 * middleware). Reaching this exception instead means brand-dependent code ran
 * outside a request — a queued job with no brand in its Context, or a console
 * command that did not go through `brand:each`. It is a programming error, not
 * a client error.
 */
final class BrandNotResolved extends RuntimeException
{
    public static function outsideLifecycle(): self
    {
        return new self(
            'No brand has been resolved for this lifecycle. A brand-scoped job '
            . 'must carry "brand" in its Context, and console work must run via '
            . '`brand:each`.'
        );
    }
}
