<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Actions;

use Illuminate\Support\Collection;
use InOtherShops\Pricing\Events\CompareAtPriceExpired;
use InOtherShops\Pricing\Events\PriceUpdated;
use InOtherShops\Pricing\Models\Price;
use InOtherShops\Pricing\Pricing;

/**
 * Promotes prices whose strikethrough window has closed: compare_at_amount
 * becomes the actual amount, and both the strikethrough amount and its end
 * date are cleared. Scheduled via pricing:expire-compare-at.
 *
 * Rows with a compare_at_until but no compare_at_amount are skipped — there
 * is nothing to promote, and writing a null amount would break the column.
 */
final class ExpireCompareAtPrices
{
    /**
     * @return Collection<int, Price> The prices whose strikethrough expired.
     */
    public function __invoke(): Collection
    {
        $model = Pricing::price();

        /** @var Collection<int, Price> $expired */
        $expired = $model::query()
            ->whereNotNull('compare_at_until')
            ->whereNotNull('compare_at_amount')
            ->where('compare_at_until', '<=', now())
            ->get();

        return $expired
            ->map(fn (Price $price): Price => $this->expire($price))
            ->values();
    }

    private function expire(Price $price): Price
    {
        $previousAmount = $price->amount;

        $price->update([
            'amount' => $price->compare_at_amount,
            'compare_at_amount' => null,
            'compare_at_until' => null,
        ]);

        // The promotion changes prices.amount, so it is a price change like any
        // other — fire PriceUpdated so cache/index/denorm listeners stay in sync,
        // and CompareAtPriceExpired for the specialized side (carries the
        // pre-promotion amount). `fromExpiry` lets PricingLogSubscriber skip the
        // generic "Price updated" line so the audit log keeps one entry. See A-4.
        PriceUpdated::dispatch($price, fromExpiry: true);
        CompareAtPriceExpired::dispatch($price, $previousAmount);

        return $price;
    }
}
