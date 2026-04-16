<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestPayable>
 */
final class TestPayableFactory extends Factory
{
    protected $model = TestPayable::class;

    public function definition(): array
    {
        return [
            'total_due' => 1000,
        ];
    }
}
