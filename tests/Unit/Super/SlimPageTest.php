<?php

declare(strict_types=1);

namespace Tests\Unit\Super;

use App\Http\Resources\Super\SlimPage;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SlimPageTest extends TestCase
{
    #[Test]
    public function it_produces_the_slim_envelope_with_a_mapper(): void
    {
        $paginator = new LengthAwarePaginator([1, 2], 5, 2, 1);

        $out = SlimPage::of($paginator, fn ($n) => ['doubled' => $n * 2])
            ->response()->getData(true);

        $this->assertSame([['doubled' => 2], ['doubled' => 4]], $out['data']);
        $this->assertSame(5, $out['total']);
        $this->assertSame(2, $out['per_page']);
        $this->assertSame(1, $out['current_page']);
        $this->assertSame(3, $out['last_page']);
        $this->assertArrayNotHasKey('message', $out);
    }

    #[Test]
    public function it_passes_items_through_without_a_mapper(): void
    {
        $paginator = new LengthAwarePaginator(['a', 'b'], 2, 25, 1);

        $out = SlimPage::of($paginator)->response()->getData(true);

        $this->assertSame(['a', 'b'], $out['data']);
    }
}
