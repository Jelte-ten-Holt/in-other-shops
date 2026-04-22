<?php

declare(strict_types=1);

namespace InOtherShops\Agent;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InOtherShops\Agent\Http\Middleware\AuthenticateAgent;
use InOtherShops\Agent\Http\Middleware\EnforceResourceParameter;
use InOtherShops\Agent\Listeners\AgentLogSubscriber;
use InOtherShops\Agent\Support\ToolRegistry;

final class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/agent.php', 'agent');

        $this->app->singleton(ToolRegistry::class);

        if ((bool) config('agent.auth.oauth.enabled', false)) {
            // Attach our RFC 8707 resource-parameter validator to every
            // Passport route (/oauth/authorize, /oauth/token, etc.).
            // Passport reads `passport.middleware` when it registers its
            // routes in its own boot(); our register runs first under the
            // default alphabetical discovery order, so this push is in
            // place by the time Passport needs it.
            $existing = (array) config('passport.middleware', []);
            if (! in_array(EnforceResourceParameter::class, $existing, true)) {
                $existing[] = EnforceResourceParameter::class;
            }
            config(['passport.middleware' => $existing]);
        }
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
                $this->registerMcpRoute();
            }

            if ((bool) config('agent.auth.oauth.enabled', false)) {
                $this->registerOauthRoutes();
            }
        });

        Event::subscribe(AgentLogSubscriber::class);
    }

    private function registerMcpRoute(): void
    {
        Route::middleware([AuthenticateAgent::class])
            ->group(__DIR__.'/Routes/mcp.php');
    }

    private function registerOauthRoutes(): void
    {
        Route::group([], __DIR__.'/Routes/oauth.php');
    }
}
