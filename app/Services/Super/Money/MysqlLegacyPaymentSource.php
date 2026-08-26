<?php

declare(strict_types=1);

namespace App\Services\Super\Money;

use Illuminate\Support\Facades\DB;

/**
 * Reads the legacy `komiut_latest_app`.`mpesas` table over the `legacy_mysql`
 * connection. See config/database.php for why that connection has no defaults.
 *
 * READ-ONLY, STRUCTURALLY. Every statement this class issues goes through the
 * single private select() below, and select() can only SELECT. That is the
 * guarantee the rest of the system relies on: legacy is a live box still taking
 * real customer payments, and a reconciliation check is the last thing that
 * should ever be able to modify the thing it is measuring. Keep it that way —
 * if this class ever needs to write, it is the wrong class.
 *
 * The server-side half of that guarantee is a SELECT-only grant for
 * LEGACY_DB_USERNAME. Ask for it; do not rely on this file alone.
 */
final class MysqlLegacyPaymentSource implements LegacyPaymentSource
{
    public const CONNECTION = 'legacy_mysql';

    public function __construct(private readonly string $connection = self::CONNECTION) {}

    /**
     * Whether the operator has actually told us where legacy is.
     *
     * Host AND username, because a half-configured connection fails deep inside
     * PDO with a message about 127.0.0.1 that sends the reader hunting in exactly
     * the wrong place. Read live from config rather than cached in a property so
     * that clearing the setting takes effect on the next run.
     */
    public function isAvailable(): bool
    {
        $config = config("database.connections.{$this->connection}");

        return is_array($config)
            && is_string($config['host'] ?? null) && $config['host'] !== ''
            && is_string($config['username'] ?? null) && $config['username'] !== '';
    }

    public function minuteBuckets(string $fromEat, string $toEat): array
    {
        // TransTime is left BARE in the WHERE clause. That is the whole
        // performance story: `mpesas` is ~21M rows and the only usable index for
        // this question is mpesas_transtime_index, which a function wrapped
        // around the column would discard. EXPLAIN against the live legacy box on
        // 2026-08-26 for a one-hour window: type=range, key=mpesas_transtime_index,
        // rows=2676. Wrap TransTime here instead and it becomes a full scan, which
        // does not merely get slow — it exceeds the server's 30s
        // max_execution_time and the check starts failing rather than reporting.
        //
        // DATE_FORMAT is therefore confined to the select list, where it only ever
        // sees rows the index already chose.
        $rows = $this->select(
            "SELECT DATE_FORMAT(TransTime, '%Y-%m-%d %H:%i') AS bucket,
                    COUNT(*) AS n,
                    COALESCE(SUM(CAST(NULLIF(TransAmount, '') AS DECIMAL(15,2))), 0) AS value
               FROM mpesas
              WHERE TransTime >= ? AND TransTime < ?
              GROUP BY bucket",
            [$fromEat, $toEat],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->bucket] = [
                'count' => (int) $row->n,
                // TransAmount is varchar(255) on both systems, so the SUM is over
                // a CAST and comes back as a string.
                'value' => (float) $row->value,
            ];
        }

        return $out;
    }

    public function payments(string $fromEat, string $toEat, int $limit): array
    {
        // LIMIT is interpolated rather than bound: MySQL will not accept a
        // placeholder there under native prepares. It is cast to int on the way
        // in, so there is nothing to inject.
        $limit = max(1, $limit);

        $rows = $this->select(
            "SELECT TransID AS trans_id,
                    COALESCE(CAST(NULLIF(TransAmount, '') AS DECIMAL(15,2)), 0) AS amount,
                    COALESCE(BusinessShortCode, '') AS shortcode,
                    DATE_FORMAT(TransTime, '%Y-%m-%d %H:%i') AS bucket
               FROM mpesas
              WHERE TransTime >= ? AND TransTime < ?
              ORDER BY TransTime
              LIMIT ".$limit,
            [$fromEat, $toEat],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->trans_id] = [
                'amount' => (float) $row->amount,
                'shortcode' => (string) $row->shortcode,
                'minute' => (string) $row->bucket,
            ];
        }

        return $out;
    }

    public function describe(): string
    {
        $config = (array) config("database.connections.{$this->connection}");

        return sprintf(
            '%s@%s:%s (mysql, read-only)',
            $config['database'] ?? '?',
            $config['host'] ?? '?',
            $config['port'] ?? '?',
        );
    }

    /**
     * The only door to legacy. Read the class docblock before adding a second one.
     *
     * @param  array<int, mixed>  $bindings
     * @return array<int, object>
     */
    private function select(string $sql, array $bindings): array
    {
        return DB::connection($this->connection)->select($sql, $bindings);
    }
}
