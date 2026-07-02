<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Models;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Database\Factories\PriceFactory;
use InOtherShops\Pricing\Exceptions\InvalidCompareAtPriceException;
use InOtherShops\Pricing\Pricing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Price extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static string $factory = PriceFactory::class;

    /**
     * Invariant: a strikethrough price is only a discount if it sits above
     * the actual price. Enforced on the model so every write path — actions,
     * both Filament surfaces, the expiry command — is covered by one guard.
     */
    protected static function booted(): void
    {
        static::saving(function (Price $price): void {
            if ($price->compare_at_amount !== null && $price->compare_at_amount <= $price->amount) {
                throw InvalidCompareAtPriceException::notAbovePrice(
                    $price->compare_at_amount,
                    $price->amount,
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'amount' => 'integer',
            'compare_at_amount' => 'integer',
            'compare_at_until' => 'datetime',
            'minimum_quantity' => 'integer',
        ];
    }

    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(Pricing::priceList());
    }

    public function formattedAmount(?string $locale = null): string
    {
        return $this->currency->format($this->amount, $locale);
    }
}
