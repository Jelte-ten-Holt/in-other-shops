<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Models;

use DateTimeInterface;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Database\Factories\PriceFactory;
use InOtherShops\Pricing\Events\PriceCreated;
use InOtherShops\Pricing\Events\PriceDeleted;
use InOtherShops\Pricing\Events\PriceUpdated;
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
     * Every write path — the admin repeater, the agent tools, imports, the
     * expiry sweep, tinker — goes through these hooks, so the guards and the
     * audit events cannot be skipped by writing the model directly (X1 of the
     * 2026-09-07 audit: the admin repeater used to bypass the old
     * CreatePrice/UpdatePrice actions and left no audit row).
     *
     * Saving:
     * - a strikethrough end date with no strikethrough amount is cleared, not
     *   refused: Filament drops the hidden date field when the amount is
     *   cleared, so refusing would turn "remove the strikethrough" into a 500;
     * - a strikethrough amount must sit above the price;
     * - a strikethrough end date may not be moved into the past: a new date
     *   in the past is refused when the row had none or had a future one. A
     *   row whose date has already passed (the sweep hasn't reached it) saves
     *   for an unrelated change even when the admin's picker rewrites the date
     *   without its seconds.
     *
     * The admin repeater checks the last two as field rules first
     * (`PricingSchema`); these are the last line.
     *
     * Created/updated/deleted dispatch the three Pricing events. `updated`
     * fires only when something changed, so an unchanged save leaves no audit
     * row. The expiry sweep saves quietly and dispatches its own
     * `PriceUpdated(fromExpiry: true)`.
     */
    protected static function booted(): void
    {
        static::saving(function (Price $price): void {
            if ($price->compare_at_amount === null) {
                $price->compare_at_until = null;
            }

            if ($price->compare_at_amount !== null && $price->compare_at_amount <= $price->amount) {
                throw InvalidCompareAtPriceException::notAbovePrice(
                    $price->compare_at_amount,
                    $price->amount,
                );
            }

            if (
                $price->isDirty('compare_at_until')
                && $price->compare_at_until !== null
                && $price->compare_at_until->getTimestamp() <= now()->getTimestamp()
                && ! self::hadElapsedEndDate($price)
            ) {
                throw InvalidCompareAtPriceException::endDateInPast($price->compare_at_until);
            }
        });

        static::created(fn (Price $price) => PriceCreated::dispatch($price));
        static::updated(fn (Price $price) => PriceUpdated::dispatch($price));
        static::deleted(fn (Price $price) => PriceDeleted::dispatch(
            (int) $price->getKey(),
            (string) $price->priceable_type,
            (int) $price->priceable_id,
        ));
    }

    private static function hadElapsedEndDate(Price $price): bool
    {
        $original = $price->getOriginal('compare_at_until');

        return $original instanceof DateTimeInterface && $original->getTimestamp() <= now()->getTimestamp();
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
