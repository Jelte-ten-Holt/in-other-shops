<?php

declare(strict_types=1);

namespace InOtherShops\Tracking;

use InOtherShops\Support\DomainServiceProvider;

/**
 * Tracking ships no log subscriber, deliberately.
 *
 * Every other model-bearing domain routes its state changes through a
 * `{Domain}LogSubscriber`; this one does not, because an attribution row IS
 * the record. Logging "an attribution was recorded" alongside the row that
 * records it doubles the write on the money path and adds nothing an operator
 * would ever read. Tracking is the first domain to skip the convention on
 * purpose — flagged here so the next reader knows it is a decision, not an
 * omission.
 */
final class TrackingServiceProvider extends DomainServiceProvider
{
    protected function domainDir(): string
    {
        return __DIR__;
    }

    protected function morphAliases(): array
    {
        return [
            'cart_item_attribution' => Tracking::cartItemAttribution(),
            'order_line_attribution' => Tracking::orderLineAttribution(),
        ];
    }

    protected function publishesConfig(): bool
    {
        return true;
    }
}
