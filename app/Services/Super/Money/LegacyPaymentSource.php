<?php

declare(strict_types=1);

namespace App\Services\Super\Money;

/**
 * The legacy side of the cross-system payment check, behind an interface for one
 * concrete reason: the suite runs on PostgreSQL only (see phpunit.xml) and there
 * is no MySQL in CI, so a reconciler that talked to MySQL directly could not be
 * tested at all.
 *
 * The split is drawn where the risk is. Everything that can be WRONG — diffing
 * minute buckets, deciding which minutes to drill into, splitting the missing
 * payments into never-arrived and arrived-but-not-recorded, the shortcode
 * breakdown — lives in CrossSystemReconciler and is exercised against an
 * in-memory implementation of this interface. MysqlLegacyPaymentSource stays a
 * thin, dumb query layer with no arithmetic in it.
 *
 * EVERY timestamp crossing this boundary is EAT (Africa/Nairobi) wall-clock,
 * formatted 'Y-m-d H:i:s'. That is not a convention picked for tidiness; it is
 * the only representation both systems agree on. See the class docblock on
 * CrossSystemReconciler for the measurement behind that claim.
 */
interface LegacyPaymentSource
{
    /**
     * Whether this source has actually been told where legacy is.
     *
     * On the contract rather than on the concrete class so the command can fail
     * closed without knowing that legacy happens to be MySQL — and so a test can
     * substitute a source that is available without also having to fake a
     * database connection into existence.
     */
    public function isAvailable(): bool;

    /**
     * Per-minute count and value over [$fromEat, $toEat).
     *
     * The cheap pass. It reads an indexed range and returns ~60 rows for an hour,
     * which is what lets the check run often without drilling into ids unless a
     * bucket actually disagrees.
     *
     * @return array<string, array{count:int, value:float}> keyed 'Y-m-d H:i' (EAT)
     */
    public function minuteBuckets(string $fromEat, string $toEat): array;

    /**
     * Every payment over [$fromEat, $toEat), keyed by TransID.
     *
     * The expensive pass, run only across the minutes the cheap pass flagged.
     * $limit is a hard stop so a pathological window (an hours-long outage, or
     * someone running --minutes=1440 by hand) cannot pull an unbounded result
     * set into memory.
     *
     * @return array<string, array{amount:float, shortcode:string, minute:string}>
     */
    public function payments(string $fromEat, string $toEat, int $limit): array;

    /** Where this data came from — printed in the report header so a run is self-evidencing. */
    public function describe(): string;
}
