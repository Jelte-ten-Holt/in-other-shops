<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Variants;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Translation\Contracts\HasTranslations;
use InOtherShops\Variants\Models\Option;
use InOtherShops\Variants\Models\OptionValue;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class OptionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function option_satisfies_the_has_translations_contract(): void
    {
        $this->assertInstanceOf(HasTranslations::class, Option::factory()->create());
    }

    #[Test]
    public function its_name_is_resolved_from_the_translations_table(): void
    {
        $option = Option::factory()->create();
        $option->setTranslation('name', 'en', 'Metal');

        $this->assertSame('Metal', $option->fresh()->name);
    }

    #[Test]
    public function it_returns_its_values_ordered_by_position(): void
    {
        $option = Option::factory()->create();
        OptionValue::factory()->for($option)->create(['value' => 'gold', 'position' => 2]);
        OptionValue::factory()->for($option)->create(['value' => 'silver', 'position' => 0]);
        OptionValue::factory()->for($option)->create(['value' => 'rose-gold', 'position' => 1]);

        $this->assertSame(
            ['silver', 'rose-gold', 'gold'],
            $option->values()->pluck('value')->all(),
        );
    }

    #[Test]
    public function deleting_an_option_cascades_to_its_values(): void
    {
        $option = Option::factory()->create();
        $value = OptionValue::factory()->for($option)->create();

        $option->delete();

        $this->assertDatabaseMissing('option_values', ['id' => $value->id]);
    }

    /**
     * Carried over from the deleted VariantsRegistryTest: bianka's Family and
     * Product rows point at these through the morph map, which is required.
     */
    #[Test]
    public function the_domain_registers_its_morph_aliases(): void
    {
        $this->assertSame(Option::class, Relation::getMorphedModel('option'));
        $this->assertSame(OptionValue::class, Relation::getMorphedModel('option_value'));
        $this->assertNull(Relation::getMorphedModel('variant'), 'the variant alias went with the model');
    }
}
