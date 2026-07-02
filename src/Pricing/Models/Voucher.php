<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Models;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Database\Factories\VoucherFactory;
use InOtherShops\Pricing\Enums\VoucherType;
use InOtherShops\Pricing\Exceptions\VoucherCurrencyMismatchException;
use InOtherShops\Pricing\Exceptions\VoucherInvalidException;
use InOtherShops\Pricing\Exceptions\VoucherMinimumNotMetException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static string $factory = VoucherFactory::class;

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

    /**
     * The single redemption guard. Both CalculateVoucherDiscount (display,
     * unlocked) and ApplyVoucher (commit, called under its row lock) run
     * exactly this — a rule change here changes both paths at once.
     *
     * @throws VoucherInvalidException
     * @throws VoucherMinimumNotMetException
     * @throws VoucherCurrencyMismatchException
     */
    public function validateForUse(int $subtotal, Currency $currency): void
    {
        if (! $this->isValid()) {
            throw VoucherInvalidException::expired($this->code);
        }

        if (! $this->meetsMinimumOrder($subtotal)) {
            throw VoucherMinimumNotMetException::forCode($this->code);
        }

        if ($this->type === VoucherType::Fixed && $this->currency !== null && $this->currency !== $currency) {
            throw VoucherCurrencyMismatchException::between($this->code, $this->currency, $currency);
        }
    }

    public function calculateDiscount(int $subtotal): int
    {
        return match ($this->type) {
            VoucherType::Fixed => min($this->amount, $subtotal),
            // Percentage vouchers store the rate in basis points (1000 = 10%),
            // matching the tax domain's convention. Round half-up for parity
            // with CalculateTax so a 10% voucher on €50.00 is exactly €5.00.
            VoucherType::Percentage => (int) round($subtotal * $this->amount / 10000),
        };
    }

    public function incrementUsage(): void
    {
        $this->increment('times_used');
    }
}
