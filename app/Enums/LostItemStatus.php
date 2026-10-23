<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * open ──found──▶ found ──collected──▶ collected
 *   │               │
 *   ├─not_found──▶ not_found
 *   └─cancel──▶ closed ◀── cancel / keep_until passed
 */
enum LostItemStatus: string
{
    case Open = 'open';
    case Found = 'found';
    case NotFound = 'not_found';
    case Collected = 'collected';
    case Closed = 'closed';

    /** The crew may only answer a report nobody has answered yet. */
    public function isAnswerable(): bool
    {
        return $this === self::Open;
    }

    /** @return array<int, string> */
    public static function categories(): array
    {
        return ['phone', 'wallet', 'bag', 'keys', 'id_document', 'clothing', 'umbrella', 'other'];
    }

    /** @return array<int, string> */
    public static function foundWheres(): array
    {
        return ['with_crew', 'at_stage', 'sacco_office'];
    }
}
