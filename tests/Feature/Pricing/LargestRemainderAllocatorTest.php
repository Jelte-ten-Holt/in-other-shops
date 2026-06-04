<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Pricing;

use InOtherShops\Pricing\Support\LargestRemainderAllocator;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared integer apportionment primitive behind tax reversal, per-bracket
 * discount allocation, and shipping-VAT apportionment. The properties that
 * matter to all three: the parts sum to the rounded target exactly, the rounding
 * cents follow the largest fractional remainders (not bucket order), ties break
 * deterministically by index, and `capAtWeight` decides whether a bucket may be
 * pushed past its own weight.
 */
final class LargestRemainderAllocatorTest extends TestCase
{
    private LargestRemainderAllocator $allocate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allocate = new LargestRemainderAllocator;
    }

    #[Test]
    public function it_distributes_an_even_split_giving_the_odd_cent_to_the_first_bucket(): void
    {
        // 100 across three equal buckets — 33/33/33 floors, one cent left; equal
        // remainders, so the index tie-break hands it to bucket 0.
        $result = ($this->allocate)([100, 100, 100], num: 1, den: 3);

        $this->assertSame([34, 33, 33], $result);
        $this->assertSame(100, array_sum($result));
    }

    #[Test]
    public function the_rounding_cent_follows_the_larger_remainder_not_the_index(): void
    {
        // weights 10 and 20, distribute round(30/3)=10. floors 3 and 6 (sum 9),
        // one cent left. bucket 1's remainder (20%3=2) beats bucket 0's (10%3=1),
        // so the cent goes to bucket 1 even though bucket 0 comes first.
        $result = ($this->allocate)([10, 20], num: 1, den: 3);

        $this->assertSame([3, 7], $result);
        $this->assertSame(10, array_sum($result));
    }

    #[Test]
    public function uncapped_allocation_reaches_the_full_target_even_past_a_bucket_weight(): void
    {
        // The shipping case: the distributed quantity is independent of the
        // weights' magnitude, so a bucket may receive more than its own weight.
        // round(3 * 5/2) = 8; floors 2 and 5 (sum 7), one cent left. It goes to
        // bucket 0 (remainder 1 > 0), pushing it to 3 — past its own weight of 1,
        // which is exactly what shipping needs and capping would forbid.
        $result = ($this->allocate)([1, 2], num: 5, den: 2, capAtWeight: false);

        $this->assertSame(8, array_sum($result));
        $this->assertSame([3, 5], $result);
    }

    #[Test]
    public function capping_refuses_to_push_a_bucket_past_its_weight(): void
    {
        // Same inputs, capped: bucket 0 is already at 2 (> its weight 1), so the
        // spare cent is refused there and — every bucket being at/over weight —
        // the target is left one short. This is the safety the reversal path
        // wants (never reverse more than was charged); shipping must NOT use it.
        $result = ($this->allocate)([1, 2], num: 5, den: 2, capAtWeight: true);

        $this->assertSame([2, 5], $result);
        $this->assertSame(7, array_sum($result));
    }

    #[Test]
    public function zero_weights_and_zero_denominator_yield_all_zeros(): void
    {
        $this->assertSame([0, 0], ($this->allocate)([0, 0], num: 5, den: 10));
        $this->assertSame([0, 0], ($this->allocate)([3, 7], num: 5, den: 0));
        $this->assertSame([], ($this->allocate)([], num: 5, den: 10));
    }
}
