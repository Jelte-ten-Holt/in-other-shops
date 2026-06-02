<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Variants;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Variants\Actions\CreateDefaultVariant;
use InOtherShops\Variants\Models\Variant;
use InOtherShops\Tests\Stubs\TestVariantable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CreateDefaultVariantTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_default_variant_carrying_the_owners_price(): void
    {
        $owner = TestVariantable::factory()->create();
        $owner->prices()->create(['amount' => 5000, 'currency' => 'EUR', 'minimum_quantity' => 1]);

        $variant = app(CreateDefaultVariant::class)($owner);

        $this->assertInstanceOf(Variant::class, $variant);
        $this->assertCount(0, $variant->optionValues);
        $this->assertSame(5000, $variant->priceFor(Currency::EUR)?->amount);
    }

    #[Test]
    public function it_carries_the_owners_stock_through_the_audit_ledger(): void
    {
        $owner = TestVariantable::factory()->create();
        StockItem::factory()->for($owner, 'stockable')->withLevel(30)->create();

        $variant = app(CreateDefaultVariant::class)($owner);

        $this->assertSame(30, $variant->stockLevel());
        // Carried via AdjustStock, so a movement is recorded — not a silent write.
        $this->assertSame(1, $variant->stockMovements()->count());
    }

    #[Test]
    public function it_is_a_no_op_when_the_owner_already_has_variants(): void
    {
        $owner = TestVariantable::factory()->create();
        Variant::factory()->for($owner, 'variantable')->create();

        $result = app(CreateDefaultVariant::class)($owner);

        $this->assertNull($result);
        $this->assertSame(1, $owner->variants()->count());
    }
}
