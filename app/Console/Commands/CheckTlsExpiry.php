<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Platform\Thresholds;
use Illuminate\Console\Command;
use Throwable;

/**
 * platform.tls.expiring (alert / high, daily) — TLS certificate expiry for each
 * brand host resolved from config('brands.*.hosts') against tls_expiry_days.
 *
 * Hosts are env-driven, so a brand with no host configured is skipped (no domain
 * to resolve — matches the "else TODO+skip" clause without fabricating one).
 */
class CheckTlsExpiry extends Command
{
    protected $signature = 'platform:check-tls';

    protected $description = 'Alert when a brand TLS certificate is within the expiry threshold';

    public function handle(PlatformNotifier $notifier): int
    {
        $checked = 0;

        foreach ((array) config('brands', []) as $brand => $conf) {
            $brand = (string) $brand;
            $days = (int) Thresholds::get($brand, 'tls_expiry_days');

            foreach ((array) ($conf['hosts'] ?? []) as $host) {
                $host = trim((string) $host);
                if ($host === '') {
                    continue;
                }

                $expiresAt = $this->certExpiry($host);
                if ($expiresAt === null) {
                    $this->warn("Could not read cert for {$host} — skipping.");

                    continue;
                }

                $checked++;
                $daysLeft = (int) floor(($expiresAt - time()) / 86400);
                if ($daysLeft > $days) {
                    continue;
                }

                $notifier->dispatch(new PlatformEvent(
                    event: 'platform.tls.expiring',
                    severity: 'high',
                    class: 'alert',
                    title: 'TLS certificate expiring',
                    summary: mb_substr("{$host} certificate expires in {$daysLeft}d (threshold {$days}d).", 0, 140),
                    brand: $brand,
                    subject: ['type' => 'host', 'id' => $host],
                    data: [
                        'host' => $host,
                        'daysLeft' => $daysLeft,
                        'thresholdDays' => $days,
                        'expiresAt' => date('c', $expiresAt),
                    ],
                    dedupeKey: 'platform.tls.expiring:'.$host,
                    windowMinutes: 24 * 60,
                ));
            }
        }

        $this->info("TLS expiry check complete — {$checked} host(s) checked.");

        return self::SUCCESS;
    }

    /** Certificate `validTo` as a unix timestamp, or null if it can't be read. */
    private function certExpiry(string $host): ?int
    {
        try {
            $context = stream_context_create([
                'ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false],
            ]);

            $client = @stream_socket_client(
                'ssl://'.$host.':443',
                $errno,
                $errstr,
                5,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if ($client === false) {
                return null;
            }

            $params = stream_context_get_params($client);
            fclose($client);

            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            if ($cert === null) {
                return null;
            }

            $parsed = openssl_x509_parse($cert);

            return isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
