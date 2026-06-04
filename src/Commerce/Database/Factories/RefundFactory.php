<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Order\Enums\RefundActorSource;
use InOtherShops\Commerce\Order\Models\Refund;

/**
 * @extends Factory<Refund>
 */
final class RefundFactory extends Factory
{
    public function modelName(): string
    {
        return Commerce::refund();
    }

    public function definition(): array
    {
        return [
            'order_id' => Commerce::order()::factory(),
            'payment_id' => null,
            'gateway' => 'fake',
            'gateway_refund_id' => 'fake_re_'.fake()->unique()->numerify('######'),
            'amount' => 1000,
            'tax_summary' => [],
            'reason' => null,
            'actor_source' => RefundActorSource::Admin,
            'actor_id' => '1',
            'actor_label' => 'Test Admin',
        ];
    }

    public function byGateway(?string $label = null): static
    {
        return $this->state(fn (): array => [
            'actor_source' => RefundActorSource::Gateway,
            'actor_id' => null,
            'actor_label' => $label,
        ]);
    }
}
