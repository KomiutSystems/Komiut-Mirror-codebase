<?php

declare(strict_types=1);

namespace App\Services\Platform;

use Illuminate\Support\Carbon;

/**
 * The value an emitter hands to PlatformNotifier. `title`/`summary` are
 * server-rendered (the console shows them verbatim, ≤ ~140 chars for summary).
 * `data` is machine-readable detail — counts, ids, hashes, before/after — and
 * MUST NOT contain PII (names, phones, ID numbers): these get emailed and cached.
 *
 * Severity: critical | high | normal. Class: alert | review | digest.
 * Set windowMinutes = 0 (default) for audit-critical events that must never be
 * throttled (payment-detail changes, super-admin role changes, lead exports).
 */
final class PlatformEvent
{
    /**
     * @param  array{type?:string,id?:string|int,label?:string}|null  $actor
     * @param  array{type?:string,id?:string|int,label?:string}|null  $subject
     * @param  array<string,mixed>|null  $data
     */
    public function __construct(
        public string $event,
        public string $severity,
        public string $class,
        public string $title,
        public string $summary,
        public ?string $brand = null,
        public ?array $actor = null,
        public ?array $subject = null,
        public ?array $data = null,
        public ?string $dedupeKey = null,
        public int $windowMinutes = 0,
        public ?int $auditId = null,
        public ?Carbon $occurredAt = null,
    ) {}
}
