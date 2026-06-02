<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Variants;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Media\Contracts\HasMedia;
use InOtherShops\Pricing\Contracts\HasPrices;
use InOtherShops\Variants\Models\Option;
use InOtherShops\Variants\Models\OptionValue;
use InOtherShops\Variants\Models\Variant;
use InOtherShops\Tests\Stubs\TestVariantable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class VariantTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_satisfies_the_purchasable_capability_contracts(): void
    {
        $variant = $this->makeVariant();

        $this->assertInstanceOf(HasPrices::class, $variant);
        $this->assertInstanceOf(HasStock::class, $variant);
        $this->assertInstanceOf(HasMedia::class, $variant);
    }

    #[Test]
    public function it_belongs_to_its_owner_under_a_stable_morph_alias(): void
    {
        $owner = TestVariantable::factory()->create();
        $variant = Variant::factory()->for($owner, 'variantable')->create();

        $this->assertDatabaseHas('variants', [
            'id' => $variant->id,
            'variantable_type' => 'test_variantable',
            'variantable_id' => $owner->id,
        ]);
        $this->assertTrue($variant->variantable->is($owner));
    }

    #[Test]
    public function option_summary_joins_value_labels_in_option_order(): void
    {
        $variant = $this->makeVariant();
        [$color, $size] = $this->twoOrderedOptions();
        $red = $this->valueLabelled($color, 'Red');
        $medium = $this->valueLabelled($size, 'M');

        // Attached size-first to prove the summary re-orders by the option's
        // position (color 0, size 1), not by attach order.
        $variant->optionValues()->attach([$medium->id, $red->id]);

        $this->assertSame('Red, M', $variant->fresh()->optionSummary());
    }

    #[Test]
    public function option_summary_falls_back_to_the_default_locale_then_the_value_code(): void
    {
        $option = Option::factory()->create();
        $translated = OptionValue::factory()->for($option)->create(['value' => 'silver', 'position' => 0]);
        $translated->setTranslation('label', 'en', 'Silver');
        $untranslated = OptionValue::factory()->for($option)->create(['value' => 'matte-gold', 'position' => 1]);

        $variant = $this->makeVariant();
        $variant->optionValues()->attach([$translated->id, $untranslated->id]);

        // 'de' has no labels — 'Silver' falls back to the 'en' translation,
        // and the untranslated value falls back to its raw value code.
        $this->assertSame('Silver, matte-gold', $variant->fresh()->optionSummary('de'));
    }

    #[Test]
    public function deleting_a_variant_clears_its_option_value_links(): void
    {
        $variant = $this->makeVariant();
        $value = OptionValue::factory()->create();
        $variant->optionValues()->attach($value->id);

        $variant->delete();

        $this->assertDatabaseMissing('option_value_variant', [
            'variant_id' => $variant->id,
            'option_value_id' => $value->id,
        ]);
    }

    private function makeVariant(): Variant
    {
        return Variant::factory()
            ->for(TestVariantable::factory()->create(), 'variantable')
            ->create();
    }

    /** @return array{0: Option, 1: Option} color (position 0), size (position 1) */
    private function twoOrderedOptions(): array
    {
        return [
            Option::factory()->create(['position' => 0]),
            Option::factory()->create(['position' => 1]),
        ];
    }

    private function valueLabelled(Option $option, string $label): OptionValue
    {
        $value = OptionValue::factory()->for($option)->create();
        $value->setTranslation('label', 'en', $label);

        return $value;
    }
}
