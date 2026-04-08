<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Models;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Enums\VoucherType;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => VoucherType::class,
            'currency' => Currency::class,
            'amount' => 'integer',
            'minimum_order_amount' => 'integer',
            'max_uses' => 'integer',
            'times_used' => 'integer',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->valid_from !== null && $this->valid_from->isFuture()) {
            return false;
        }

        if ($this->valid_until !== null && $this->valid_until->isPast()) {
            return false;
        }

        if ($this->max_uses !== null && $this->times_used >= $this->max_uses) {
            return false;
        }

        return true;
    }

    public function meetsMinimumOrder(int $subtotal): bool
    {
        return $subtotal >= $this->minimum_order_amount;
    }

    public function calculateDiscount(int $subtotal): int
    {
        return match ($this->type) {
            VoucherType::Fixed => min($this->amount, $subtotal),
            VoucherType::Percentage => (int) floor($subtotal * $this->amount / 100),
        };
    }

    public function incrementUsage(): void
    {
        $this->increment('times_used');
    }
}
