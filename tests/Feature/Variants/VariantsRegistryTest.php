<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Variants;

use Illuminate\Database\Eloquent\Relations\Relation;
use InOtherShops\Variants\Models\Option;
use InOtherShops\Variants\Models\OptionValue;
use InOtherShops\Variants\Models\Variant;
use InOtherShops\Variants\Variants;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class VariantsRegistryTest extends TestCase
{
    #[Test]
    public function the_registry_resolves_the_default_models(): void
    {
        $this->assertSame(Option::class, Variants::option());
        $this->assertSame(OptionValue::class, Variants::optionValue());
        $this->assertSame(Variant::class, Variants::variant());
    }

    #[Test]
    public function the_domain_registers_its_morph_aliases(): void
    {
        $this->assertSame(Option::class, Relation::getMorphedModel('option'));
        $this->assertSame(OptionValue::class, Relation::getMorphedModel('option_value'));
        $this->assertSame(Variant::class, Relation::getMorphedModel('variant'));
    }
}
