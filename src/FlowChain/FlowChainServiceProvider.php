<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain;

use InOtherShops\FlowChain\Listeners\FlowChainLogSubscriber;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class FlowChainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::subscribe(FlowChainLogSubscriber::class);
    }
}
