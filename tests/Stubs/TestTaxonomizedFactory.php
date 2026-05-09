<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestTaxonomized>
 */
final class TestTaxonomizedFactory extends Factory
{
    protected $model = TestTaxonomized::class;

    public function definition(): array
    {
        return [
            'name' => fake()->word(),
        ];
    }
}
