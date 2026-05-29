<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Listeners;

use InOtherShops\Logging\DTOs\LogEntry;
use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\LogDispatcher;
use InOtherShops\Pricing\Events\CompareAtPriceExpired;
use InOtherShops\Pricing\Events\PriceCreated;
use InOtherShops\Pricing\Events\PriceDeleted;
use InOtherShops\Pricing\Events\PriceUpdated;
use InOtherShops\Pricing\Events\VoucherApplied;
use Illuminate\Contracts\Events\Dispatcher;

final class PricingLogSubscriber
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
    private const string CHANNEL = 'commerce';

    public function __construct(
        private readonly LogDispatcher $dispatcher,
    ) {}

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
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: 'Price created.',
            context: $this->priceContext($event->price),
        ));
    }

    public function handlePriceUpdated(PriceUpdated $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: 'Price updated.',
            context: $this->priceContext($event->price),
        ));
    }

    public function handlePriceDeleted(PriceDeleted $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: 'Price deleted.',
            context: [
                'price_id' => $event->priceId,
                'priceable_type' => $event->priceableType,
                'priceable_id' => $event->priceableId,
            ],
        ));
    }

    public function handleCompareAtPriceExpired(CompareAtPriceExpired $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: 'Strikethrough price expired.',
            context: [
                'price_id' => $event->price->id,
                'priceable_type' => $event->price->priceable_type,
                'priceable_id' => $event->price->priceable_id,
                'currency' => $event->price->currency?->value,
                'previous_amount' => $event->previousAmount,
                'amount' => $event->price->amount,
            ],
        ));
    }

    public function handleVoucherApplied(VoucherApplied $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: "Voucher applied: {$event->voucher->code}.",
            context: [
                'voucher_id' => $event->voucher->id,
                'code' => $event->voucher->code,
                'type' => $event->voucher->type->value,
                'times_used' => $event->voucher->times_used,
                'max_uses' => $event->voucher->max_uses,
            ],
        ));
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
