<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Copy a C2B confirmation back to the legacy payments tier, so rollback stays
 * cheap for as long as we want it to.
 *
 * WHAT THIS IS FOR. Moving payments.komiut.com to Frankfurt is a one-record DNS
 * change with a 60-second TTL, so rolling FORWARD is easy. Rolling BACK is only
 * easy if legacy's ledger has no hole in it: the moment the record points here,
 * komiut_payments stops receiving, and every minute we run on Frankfurt is a
 * minute legacy cannot account for. If we then rolled back, legacy would resume
 * mid-stream having missed everything in between, and the two ledgers could never
 * be reconciled against each other again — which is the one check that has been
 * telling us the migration is safe. This job keeps legacy whole so the rollback
 * decision stays reversible rather than one-way.
 *
 * IT IS THE OPPOSITE OF THE RELAY IT REPLACES, ON PURPOSE. Legacy's
 * ProcessMpesaPayments relays to us inline with `timeout(1)`, `connectTimeout(1)`,
 * no retry, and `catch (\Throwable) {}` — and the Co-op receiver does the same
 * thing at a dead ngrok tunnel it has been posting to since June. That design
 * loses money silently, which is exactly why nobody noticed. This one is QUEUED
 * (so it cannot slow the ack Safaricom is waiting for), RETRIES with backoff, and
 * LOGS every failure with its TransID so a lost mirror is recoverable by hand.
 *
 * RETRIES ARE SAFE because the receiving end dedupes: legacy's
 * MpesaAPIController::mpesaConfirmation and ProcessMpesaPayments both do
 * `Mpesa::where('TransID', ...)->first()` and update that row rather than
 * inserting a second one (verified on i-0b890fe2886cbb723, 2026-09-09). A
 * repeated POST of the same payment updates; it does not duplicate.
 *
 * ---------------------------------------------------------------------------
 * THE SELF-POST GUARD IS NOT PARANOIA. IT IS THE DEFAULT CONFIG.
 *
 * services.legacy_payments.url defaults to `https://payments.komiut.com` — the
 * very hostname this migration repoints at Frankfurt. The instant that DNS record
 * moves, that URL resolves to US. Without the guard below, every confirmation
 * would be mirrored to ourselves, recorded again, mirrored again: an unbounded
 * loop, on the money path, growing with every payment, and it would look like
 * traffic rather than like a bug.
 *
 * So the target must be a hostname that still points at Mumbai — the intended
 * value is `https://legacy-payments.komiut.com`, an alias to
 * komiut-payments-alb-131134297.ap-south-1.elb.amazonaws.com, which the Mumbai
 * ALB's `*.komiut.com` certificate already covers. The guard refuses to post to
 * any host this application itself answers on, and refuses loudly.
 * ---------------------------------------------------------------------------
 */
final class MirrorConfirmationToLegacy implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Five attempts over ~8 minutes; legacy being briefly down must not lose a mirror. */
    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 120, 300];

    /**
     * @param  array<string, mixed>  $fields  the confirmation body exactly as Safaricom sent it
     */
    public function __construct(
        private readonly array $fields,
        private readonly string $settingId,
    ) {
    }

    public function handle(): void
    {
        if (! (bool) config('services.legacy_payments.mirror', false)) {
            return;
        }

        $base = rtrim((string) config('services.legacy_payments.url', ''), '/');

        if ($base === '') {
            return;
        }

        if ($this->pointsAtUs($base)) {
            // Fail fast and do NOT retry — retrying a self-post just loops harder.
            Log::error('mirror to legacy refused: the target is this application', [
                'target' => $base,
                'trans_id' => $this->fields['TransID'] ?? null,
                'hint' => 'set LEGACY_PAYMENTS_URL to a hostname that still resolves to Mumbai',
            ]);
            $this->fail(new \RuntimeException('legacy mirror target resolves to this application: '.$base));

            return;
        }

        // Generous timeouts: this is off the critical path by construction, so
        // the only cost of waiting is a queue slot, and giving up early is how
        // the legacy relay lost payments.
        $response = Http::timeout(15)
            ->connectTimeout(10)
            ->acceptJson()
            ->post($base.'/api/confirmation/'.$this->settingId, $this->fields);

        if ($response->failed()) {
            // Throw so the queue retries with backoff. The final failure lands in
            // failed(), which is where the TransID becomes recoverable by hand.
            $response->throw();
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::error('mirror to legacy failed after every retry', [
            'trans_id' => $this->fields['TransID'] ?? null,
            'short_code' => $this->fields['BusinessShortCode'] ?? null,
            'amount' => $this->fields['TransAmount'] ?? null,
            'setting_id' => $this->settingId,
            // The class, not just the message: a catch that hides why it caught
            // is worse than no catch.
            'exception' => $e ? $e::class : null,
            'message' => $e?->getMessage(),
        ]);
    }

    /**
     * Is this URL a hostname we ourselves answer on?
     *
     * Checked against the brand registry's own host list rather than a literal,
     * so it stays correct as hostnames are added during the migration — including
     * payments.komiut.com the moment it is listed, which is BEFORE its DNS moves.
     * That ordering is what makes the guard fire before it can ever loop.
     */
    private function pointsAtUs(string $base): bool
    {
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        foreach ((array) config('brands', []) as $definition) {
            foreach ((array) ($definition['hosts'] ?? []) as $ours) {
                if ($host === strtolower(trim((string) $ours))) {
                    return true;
                }
            }
        }

        return false;
    }
}
