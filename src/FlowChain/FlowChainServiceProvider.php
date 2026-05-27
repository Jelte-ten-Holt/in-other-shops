<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain;

use InOtherShops\FlowChain\Console\Commands\CheckContractsCommand;
use InOtherShops\FlowChain\Console\Commands\ListChainsCommand;
use InOtherShops\FlowChain\Console\Commands\PublishChainCommand;
use InOtherShops\FlowChain\Console\Commands\VerifyTestsCommand;
use InOtherShops\FlowChain\Listeners\FlowChainLogSubscriber;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class FlowChainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FlowChainRegistry::class, fn () => new FlowChainRegistry(
            publishBasePath: app_path('Project/FlowChains'),
        ));
    }

    public function boot(): void
    {
        Event::subscribe(FlowChainLogSubscriber::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                PublishChainCommand::class,
                ListChainsCommand::class,
                CheckContractsCommand::class,
                VerifyTestsCommand::class,
            ]);
        }
    }
}
