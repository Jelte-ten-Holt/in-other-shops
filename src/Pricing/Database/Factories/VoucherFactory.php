<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Database\Factories;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Enums\VoucherType;
use InOtherShops\Pricing\Models\Voucher;
use InOtherShops\Pricing\Pricing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Voucher>
 */
final class VoucherFactory extends Factory
{
    public function modelName(): string
    {
        return Pricing::voucher()::class;
    }

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('SAVE####')),
            'type' => VoucherType::Fixed,
            'amount' => 1000,
            'currency' => Currency::EUR,
            'minimum_order_amount' => 0,
            'max_uses' => null,
            'times_used' => 0,
            'valid_from' => null,
            'valid_until' => null,
            'is_active' => true,
        ];
    }

    public function percentage(int $percent = 10): self
    {
        return $this->state(fn () => [
            'type' => VoucherType::Percentage,
            'amount' => $percent,
            'currency' => null,
        ]);
    }

    public function withMaxUses(int $max, int $used = 0): self
    {
        return $this->state(fn () => [
            'max_uses' => $max,
            'times_used' => $used,
        ]);
    }

    public function expired(): self
    {
        return $this->state(fn () => [
            'valid_until' => now()->subDay(),
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
