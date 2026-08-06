<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\UserType;
use App\Models\User;
use App\Services\Cache\ScopedCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The cache must not become a way around BrandScope.
 *
 * BrandScope::apply() has three outcomes — no brand in Context (no filtering),
 * super admin (no filtering), and everyone else (filtered by brand). There is a
 * case here for each, because a key that is shared across any two of them serves
 * one audience the rows of another, and SQL-level isolation would not save us.
 */
final class ScopedCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Context::flush();
        parent::tearDown();
    }

    /** Records how many times the closure actually ran. */
    private function counted(string $bucket, string $value, int &$runs): string
    {
        return ScopedCache::remember($bucket, ['k' => 'same'], 60, function () use ($value, &$runs): string {
            $runs++;

            return $value;
        });
    }

    #[Test]
    public function the_same_brand_reuses_its_entry(): void
    {
        // Guards against a false green elsewhere: if caching silently never
        // engaged, every isolation test below would pass for the wrong reason.
        $runs = 0;
        Context::add('brand', 'komiut');

        $this->assertSame('first', $this->counted('b', 'first', $runs));
        $this->assertSame('first', $this->counted('b', 'second', $runs), 'Second read must be a cache hit.');
        $this->assertSame(1, $runs, 'The closure must run exactly once for one brand.');
    }

    #[Test]
    public function one_brand_never_reads_another_brands_entry(): void
    {
        $runs = 0;

        Context::add('brand', 'komiut');
        $this->assertSame('komiut-data', $this->counted('b', 'komiut-data', $runs));

        Context::flush();
        Context::add('brand', 'safiri');
        $safiri = $this->counted('b', 'safiri-data', $runs);

        $this->assertSame('safiri-data', $safiri, 'safiri must not be served komiut\'s cached rows.');
        $this->assertSame(2, $runs);
    }

    #[Test]
    public function a_super_admins_unscoped_entry_is_not_served_to_a_brand(): void
    {
        // The dangerous direction: a super admin bypasses BrandScope, so their
        // cached result contains every brand's rows. A brand user reading that
        // key would see across the boundary.
        $runs = 0;

        $super = User::factory()->create();
        $super->forceFill(['type' => UserType::Superadmin])->save();

        Context::add('brand', 'komiut');
        Auth::login($super);
        $this->assertSame('all-brands', $this->counted('b', 'all-brands', $runs));

        Auth::logout();
        $ordinary = User::factory()->create();
        Auth::login($ordinary);

        $this->assertSame(
            'komiut-only',
            $this->counted('b', 'komiut-only', $runs),
            'A brand user must not inherit the super admin\'s unscoped entry.',
        );
        $this->assertSame(2, $runs);
    }

    #[Test]
    public function a_brandless_context_is_its_own_segment(): void
    {
        // Console commands and queued jobs run with no brand and are therefore
        // unfiltered. That result must not be handed to a brand request.
        $runs = 0;

        $this->assertFalse(Context::has('brand'));
        $this->assertSame('console', $this->counted('b', 'console', $runs));

        Context::add('brand', 'komiut');

        $this->assertSame('komiut', $this->counted('b', 'komiut', $runs), 'A brand request must not read the brandless entry.');
        $this->assertSame(2, $runs);
    }

    #[Test]
    public function busting_invalidates_every_brand_at_once(): void
    {
        // Reference data is global, so a write must not leave another brand
        // serving the pre-write value.
        $runs = 0;

        Context::add('brand', 'komiut');
        $this->counted('b', 'before', $runs);

        Context::flush();
        Context::add('brand', 'safiri');
        $this->counted('b', 'before', $runs);

        ScopedCache::bust('b');

        $this->assertSame('after', $this->counted('b', 'after', $runs));

        Context::flush();
        Context::add('brand', 'komiut');
        $this->assertSame('after', $this->counted('b', 'after', $runs), 'The bust must reach komiut too.');

        $this->assertSame(4, $runs);
    }

    #[Test]
    public function busting_one_bucket_leaves_others_alone(): void
    {
        $runs = 0;
        Context::add('brand', 'komiut');

        $this->counted('genders', 'g', $runs);
        $this->counted('places', 'p', $runs);

        ScopedCache::bust('genders');

        $this->assertSame('g2', $this->counted('genders', 'g2', $runs));
        $this->assertSame('p', $this->counted('places', 'p2', $runs), 'places must survive a genders bust.');
        $this->assertSame(3, $runs);
    }

    #[Test]
    public function bust_works_when_no_counter_exists_yet(): void
    {
        // The counter is absent until the first bust; readers default it to 1,
        // so seeding must land on a different value or the bust is a no-op.
        $runs = 0;
        Context::add('brand', 'komiut');

        $this->counted('fresh', 'v1', $runs);
        $this->assertNull(Cache::get('sc:ver:fresh'), 'Precondition: no counter yet.');

        ScopedCache::bust('fresh');

        $this->assertSame('v2', $this->counted('fresh', 'v2', $runs));
        $this->assertSame(2, $runs);
    }
}
