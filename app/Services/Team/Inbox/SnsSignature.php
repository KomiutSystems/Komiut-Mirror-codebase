<?php

declare(strict_types=1);

namespace App\Services\Team\Inbox;

/**
 * The outcome of checking an SNS message's signature. Unavailable is kept
 * apart from Invalid because SNS treats the answers differently: a 4xx is
 * final and the email is dropped, a 5xx is retried. A certificate we could
 * not fetch this minute says nothing about the message, so it must be
 * retried, not refused.
 */
enum SnsSignature
{
    /** Signed by SNS with a certificate from SNS. */
    case Valid;

    /** Wrong key, altered fields, a certificate URL that is not SNS's, an unknown version. */
    case Invalid;

    /** The signing certificate could not be fetched or read just now (timeout, DNS, 5xx). */
    case Unavailable;
}
