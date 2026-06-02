<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Variants;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Variants\Models\Option;
use InOtherShops\Variants\Models\OptionValue;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class OptionValueTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_belongs_to_its_option(): void
    {
        $option = Option::factory()->create();
        $value = OptionValue::factory()->for($option)->create();

        $this->assertTrue($value->option->is($option));
    }

    #[Test]
    public function its_label_is_resolved_from_the_translations_table(): void
    {
        $value = OptionValue::factory()->create();
        $value->setTranslation('label', 'en', 'Silver');

        $this->assertSame('Silver', $value->fresh()->label);
    }

    #[Test]
    public function a_value_code_is_unique_within_its_option(): void
    {
        $option = Option::factory()->create();
        OptionValue::factory()->for($option)->create(['value' => 'silver']);

        $this->expectException(QueryException::class);

        OptionValue::factory()->for($option)->create(['value' => 'silver']);
    }

    #[Test]
    public function the_same_value_code_is_allowed_under_different_options(): void
    {
        $metal = Option::factory()->create();
        $finish = Option::factory()->create();

        OptionValue::factory()->for($metal)->create(['value' => 'matte']);
        $second = OptionValue::factory()->for($finish)->create(['value' => 'matte']);

        $this->assertDatabaseCount('option_values', 2);
        $this->assertTrue($second->option->is($finish));
    }
}
