<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Database\Factories;

use InOtherShops\Variants\Models\Variant;
use InOtherShops\Variants\Variants;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Variant>
 *
 * The polymorphic `variantable` owner is consumer-specific, so it is not set
 * here — callers attach one via `->for($owner, 'variantable')`.
 */
final class VariantFactory extends Factory
{
    public function modelName(): string
    {
        return Variants::variant();
    }

    public function definition(): array
    {
        return [
            'sku' => Str::upper(Str::random(8)),
            'position' => 0,
        ];
    }
}
