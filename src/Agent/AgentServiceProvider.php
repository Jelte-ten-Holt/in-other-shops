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
        // Route registration is deferred to `app.booted` because the `/mcp`
        // route file calls the `Route::mcp(...)` macro from opgginc/laravel-
        // -mcp-server, and that macro is installed in the opgginc provider's
        // own `boot()`. Composer package-discovery boot order is alphabetical,
        // so `jelte-ten-holt/in-other-shops` boots before `opgginc/*` in any
        // consuming app — registering directly in boot() would race the macro.
        $this->app->booted(function (): void {
            if (config('agent.route.enabled', true)) {
                $this->registerRoute();
            }
        });

        Event::subscribe(AgentLogSubscriber::class);
    }

    private function registerRoute(): void
    {
        Route::middleware([AuthenticateAgentBearer::class])
            ->group(__DIR__.'/Routes/mcp.php');
    }
}
