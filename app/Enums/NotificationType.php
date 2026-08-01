<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The notification categories the mobile app renders (icon + deep-link). The
 * backing string is what the app switch-maps on, lowercased.
 *
 * Only values with a real trigger are defined — the C# system carried six but
 * ever emitted only one, leaving five aspirational placeholders. Add a case here
 * when, and only when, something actually dispatches it.
 *
 * Deep-link note (from the app): only `Trip` with a non-empty referenceId
 * deep-links (to the passenger ticket); every other type opens the list. So a
 * notification meant to open the booking uses Trip + referenceId = booking id.
 */
enum NotificationType: string
{
    case Trip = 'trip';
    case Payment = 'payment';
    case Assignment = 'assignment';
    case Promo = 'promo';
    case System = 'system';
}
