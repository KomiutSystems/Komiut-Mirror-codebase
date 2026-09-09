<?php

declare(strict_types=1);

namespace Tests\Feature\Brands;

use App\Brands\BrandRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The money hostnames must resolve to a brand BEFORE their DNS points here.
 *
 * WHY THIS IS A MONEY TEST AND NOT A CONFIG TEST.
 *
 * ~98.6% of Komiut's revenue arrives as Safaricom C2B confirmations POSTed to
 * `payments.komiut.com/api/confirmation/{mpesa_setting_id}`. That hostname is an
 * ordinary Route53 ALIAS record in our own zone, pointing at the Mumbai ALB, and
 * an alias to an ALB resolves on a 60-second TTL. Repointing it at Frankfurt is
 * therefore a one-record change that moves the entire fleet's money in about a
 * minute, with no Safaricom involvement and no per-till re-registration — the
 * sender keeps posting to the same URL, the name just resolves somewhere else.
 *
 * BrandRegistry fails closed by design: `resolveByHost()` returns null for a host
 * it does not know, and the request 404s before reaching a controller. Verified
 * against production on 2026-09-09 — `https://payments.komiut.com/api/up` served
 * by the Frankfurt ALB returns 200 with a valid certificate (the ALB already
 * carries a `komiut.com, *.komiut.com` wildcard, issued 2026-09-06), while
 * `/api/confirmation/3` on that same Host returns 404. The TLS half is done; this
 * is the half that is not.
 *
 * So if the DNS record moved while the host were unlisted, every confirmation
 * would 404 — and be LOST, not retried. The legacy sender posts with a 2s
 * timeout, no retry, and `catch (\Throwable) { // Ignore all errors }`. There is
 * no queue, no dead-letter, no second attempt. At the measured ~31 payments a
 * minute, each minute of 404s is about 31 payments nobody can reconstruct.
 *
 * The ordering rule this test enforces: list the hostname FIRST, deploy, prove it
 * with a Host-header request, and only then touch DNS. Listing a host early is
 * free — nothing routes here until the record says so.
 */
final class PaymentsHostnameIsServedTest extends TestCase
{
    /** @param array<int, string> $extra */
    private function registryWithHosts(array $extra): BrandRegistry
    {
        return new BrandRegistry([
            'komiut' => [
                'name' => 'Komiut',
                'hosts' => array_merge(['komiut.com', 'api.komiut.com'], $extra),
                'app_key' => 'k',
            ],
        ]);
    }

    #[Test]
    public function the_c2b_receiver_hostname_resolves_to_the_komiut_brand(): void
    {
        $registry = $this->registryWithHosts(['payments.komiut.com']);

        $brand = $registry->resolveByHost('payments.komiut.com');

        $this->assertNotNull(
            $brand,
            'payments.komiut.com carries ~98.6% of revenue; unresolved means every confirmation 404s and is lost',
        );
    }

    #[Test]
    public function an_unlisted_host_still_fails_closed(): void
    {
        // The safety property that makes listing hosts explicitly worth the
        // trouble: we must never accidentally serve a hostname we do not own.
        $registry = $this->registryWithHosts(['payments.komiut.com']);

        $this->assertNull($registry->resolveByHost('evil.example.com'));
        $this->assertNull($registry->resolveByHost('bankpayments.komiut.com'),
            'not listed yet — Co-op moves on its own schedule, and until then this must not resolve');
    }

    #[Test]
    public function the_existing_hostnames_keep_working(): void
    {
        // Adding hosts must not disturb the two the platform already answers on.
        // api.komiut.com is every mobile client; komiut.com is the NCBA aggregator's
        // registered confirmation URL.
        $registry = $this->registryWithHosts(['payments.komiut.com']);

        $this->assertNotNull($registry->resolveByHost('api.komiut.com'));
        $this->assertNotNull($registry->resolveByHost('komiut.com'));
    }

    #[Test]
    public function a_host_is_matched_case_insensitively_and_without_its_port(): void
    {
        // Safaricom is not the only caller and nothing guarantees the Host header's
        // casing. normaliseHost() lowercases and strips a port; this pins it for the
        // hostname where getting it wrong costs money.
        $registry = $this->registryWithHosts(['payments.komiut.com']);

        $this->assertNotNull($registry->resolveByHost('PAYMENTS.komiut.com'));
        $this->assertNotNull($registry->resolveByHost('payments.komiut.com:443'));
    }

    #[Test]
    public function the_config_file_reads_a_comma_separated_list(): void
    {
        // config/brands.php builds `hosts` from KOMIUT_HOST, KOMIUT_HOST_ALT and a
        // comma-separated KOMIUT_HOSTS. The two fixed slots were both already in
        // use, so without the list there is nowhere to put a third hostname — and
        // the migration needs at least two more.
        putenv('KOMIUT_HOST=komiut.com');
        putenv('KOMIUT_HOST_ALT=api.komiut.com');
        putenv('KOMIUT_HOSTS= payments.komiut.com , bankpayments.komiut.com ');

        try {
            $hosts = require base_path('config/brands.php');
            $komiut = $hosts['komiut']['hosts'];

            $this->assertContains('payments.komiut.com', $komiut, 'whitespace around a list entry must be tolerated');
            $this->assertContains('bankpayments.komiut.com', $komiut);
            $this->assertContains('komiut.com', $komiut);
            $this->assertContains('api.komiut.com', $komiut);
            $this->assertSame(array_values(array_unique($komiut)), $komiut, 'a repeated host must not be listed twice');
        } finally {
            putenv('KOMIUT_HOSTS');
        }
    }
}
