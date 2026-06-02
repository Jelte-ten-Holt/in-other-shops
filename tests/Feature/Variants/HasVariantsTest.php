<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Variants;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Variants\Models\Option;
use InOtherShops\Variants\Models\Variant;
use InOtherShops\Tests\Stubs\TestVariantable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class HasVariantsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_owner_has_no_variants_until_one_is_created(): void
    {
        $owner = TestVariantable::factory()->create();

        $this->assertFalse($owner->hasVariants());

        Variant::factory()->for($owner, 'variantable')->create();

        $this->assertTrue($owner->fresh()->hasVariants());
    }

    #[Test]
    public function has_variants_reads_a_loaded_relation_without_a_query(): void
    {
        $owner = TestVariantable::factory()->create();
        Variant::factory()->for($owner, 'variantable')->create();
        $owner->load('variants');

        $this->assertTrue($owner->hasVariants());
    }

    #[Test]
    public function it_lists_its_variants_ordered_by_position(): void
    {
        $owner = TestVariantable::factory()->create();
        Variant::factory()->for($owner, 'variantable')->create(['sku' => 'B', 'position' => 1]);
        Variant::factory()->for($owner, 'variantable')->create(['sku' => 'A', 'position' => 0]);

        $this->assertSame(['A', 'B'], $owner->variants()->pluck('sku')->all());
    }

    #[Test]
    public function it_declares_its_axes_ordered_by_pivot_position(): void
    {
        $owner = TestVariantable::factory()->create();
        $metal = Option::factory()->create();
        $size = Option::factory()->create();

        // Attached metal-second to prove ordering comes from the pivot position,
        // not attach order.
        $owner->options()->attach($size->id, ['position' => 1]);
        $owner->options()->attach($metal->id, ['position' => 0]);

        $this->assertSame(
            [$metal->id, $size->id],
            $owner->options()->pluck('options.id')->all(),
        );
    }

    #[Test]
    public function declared_axes_are_stored_under_the_owner_morph_alias(): void
    {
        $owner = TestVariantable::factory()->create();
        $option = Option::factory()->create();

        $owner->options()->attach($option->id, ['position' => 0]);

        $this->assertDatabaseHas('optionables', [
            'option_id' => $option->id,
            'optionable_type' => 'test_variantable',
            'optionable_id' => $owner->id,
        ]);
    }
}
