<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Variants;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Variants\Models\Variant;
use InOtherShops\Tests\Stubs\TestVariantable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class VariantAggregationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function lowest_variant_price_is_the_minimum_across_priced_variants(): void
    {
        $owner = TestVariantable::factory()->create();
        $this->variantPriced($owner, 3000);
        $this->variantPriced($owner, 2000);
        Variant::factory()->for($owner, 'variantable')->create(); // unpriced — ignored

        $this->assertSame(2000, $owner->fresh()->lowestVariantPrice(Currency::EUR));
    }

    #[Test]
    public function lowest_variant_price_is_null_when_no_variant_is_priced(): void
    {
        $owner = TestVariantable::factory()->create();
        Variant::factory()->for($owner, 'variantable')->create();

        $this->assertNull($owner->fresh()->lowestVariantPrice(Currency::EUR));
    }

    #[Test]
    public function it_aggregates_stock_across_variants(): void
    {
        $owner = TestVariantable::factory()->create();
        $this->variantStocked($owner, 4);
        $this->variantStocked($owner, 6);
        $this->variantStocked($owner, 0);

        $owner = $owner->fresh();

        $this->assertTrue($owner->hasVariantInStock());
        $this->assertSame(10, $owner->variantStockTotal());
    }

    #[Test]
    public function it_reports_out_of_stock_when_every_variant_is_empty(): void
    {
        $owner = TestVariantable::factory()->create();
        $this->variantStocked($owner, 0);
        $this->variantStocked($owner, 0);

        $owner = $owner->fresh();

        $this->assertFalse($owner->hasVariantInStock());
        $this->assertSame(0, $owner->variantStockTotal());
    }

    private function variantPriced(TestVariantable $owner, int $amount): Variant
    {
        $variant = Variant::factory()->for($owner, 'variantable')->create();
        $variant->prices()->create(['amount' => $amount, 'currency' => 'EUR', 'minimum_quantity' => 1]);

        return $variant;
    }

    private function variantStocked(TestVariantable $owner, int $level): Variant
    {
        $variant = Variant::factory()->for($owner, 'variantable')->create();
        StockItem::factory()->for($variant, 'stockable')->withLevel($level)->create();

        return $variant;
    }
}
