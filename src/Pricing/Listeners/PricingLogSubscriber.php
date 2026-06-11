<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Listeners;

use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\LogSubscriberBase;
use InOtherShops\Pricing\Events\CompareAtPriceExpired;
use InOtherShops\Pricing\Events\PriceCreated;
use InOtherShops\Pricing\Events\PriceDeleted;
use InOtherShops\Pricing\Events\PriceUpdated;
use InOtherShops\Pricing\Events\VoucherApplied;
use Illuminate\Contracts\Events\Dispatcher;

final class PricingLogSubscriber extends LogSubscriberBase
{
    /**
     * Deliberately 'commerce', not 'pricing'. Price changes are commercial
     * audit events and belong in the same stream as orders/payments; there is
     * no separate 'pricing' channel in the default Logging config. This is the
     * intentional exception to the otherwise per-domain channel convention —
     * do not "fix" it to 'pricing' without first adding a pricing channel to
     * the package default config and every consumer's domain-log config.
     * See audit finding D-2.
     */
    protected const string CHANNEL = 'commerce';

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            PriceCreated::class => 'handlePriceCreated',
            PriceUpdated::class => 'handlePriceUpdated',
            PriceDeleted::class => 'handlePriceDeleted',
            CompareAtPriceExpired::class => 'handleCompareAtPriceExpired',
            VoucherApplied::class => 'handleVoucherApplied',
        ];
    }

    public function handlePriceCreated(PriceCreated $event): void
    {
        $this->log(LogLevel::Info, 'Price created.', $this->priceContext($event->price));
    }

    public function handlePriceUpdated(PriceUpdated $event): void
    {
        // The scheduled strikethrough promotion dispatches PriceUpdated (for
        // side-effect consumers) alongside CompareAtPriceExpired (which this
        // subscriber logs). Skip the generic line so the audit keeps one entry.
        if ($event->fromExpiry) {
            return;
        }

        $this->log(LogLevel::Info, 'Price updated.', $this->priceContext($event->price));
    }

    public function handlePriceDeleted(PriceDeleted $event): void
    {
        $this->log(LogLevel::Info, 'Price deleted.', [
                'price_id' => $event->priceId,
                'priceable_type' => $event->priceableType,
                'priceable_id' => $event->priceableId,
            ]);
    }

    public function handleCompareAtPriceExpired(CompareAtPriceExpired $event): void
    {
        $this->log(LogLevel::Info, 'Strikethrough price expired.', [
                'price_id' => $event->price->id,
                'priceable_type' => $event->price->priceable_type,
                'priceable_id' => $event->price->priceable_id,
                'currency' => $event->price->currency?->value,
                'previous_amount' => $event->previousAmount,
                'amount' => $event->price->amount,
            ]);
    }

    public function handleVoucherApplied(VoucherApplied $event): void
    {
        $this->log(LogLevel::Info, "Voucher applied: {$event->voucher->code}.", [
                'voucher_id' => $event->voucher->id,
                'code' => $event->voucher->code,
                'type' => $event->voucher->type->value,
                'times_used' => $event->voucher->times_used,
                'max_uses' => $event->voucher->max_uses,
            ]);
    }

    /** @return array<string, mixed> */
    private function priceContext(\InOtherShops\Pricing\Models\Price $price): array
    {
        return [
            'price_id' => $price->id,
            'priceable_type' => $price->priceable_type,
            'priceable_id' => $price->priceable_id,
            'currency' => $price->currency?->value,
            'amount' => $price->amount,
            'price_list_id' => $price->price_list_id,
        ];
    }
}
