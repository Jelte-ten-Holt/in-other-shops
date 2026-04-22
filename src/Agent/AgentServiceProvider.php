<?php

declare(strict_types=1);

namespace InOtherShops\Agent;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InOtherShops\Agent\Http\Middleware\AuthenticateAgentBearer;
use InOtherShops\Agent\Listeners\AgentLogSubscriber;
use InOtherShops\Agent\Support\ToolRegistry;

final class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/agent.php', 'agent');

        $this->app->singleton(ToolRegistry::class);
    }

    public function boot(): void
    {
        if (config('agent.route.enabled', true)) {
            $this->registerRoute();
        }

        Event::subscribe(AgentLogSubscriber::class);
    }

    private function registerRoute(): void
    {
        Route::middleware([AuthenticateAgentBearer::class])
            ->group(__DIR__.'/Routes/mcp.php');
    }
}
