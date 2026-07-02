<?php

declare(strict_types=1);

namespace InOtherShops\Support;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Base for domain service providers: implements the canonical register/boot
 * sequence (merge config → load migrations → morph map → log subscriber →
 * commands → publishes) so a domain provider declares WHAT it contributes
 * via hooks and the sequence itself can't drift per domain. Providers with
 * extra behavior (routes, observers, singletons, schedules) override
 * register()/boot() and call parent first — the hooks stay declarative.
 *
 * Hooks return values, never act: domainDir() must be the child's __DIR__
 * (a base-class __DIR__ resolves to src/Support/), configKey() defaults to
 * the lowercased domain directory name and must match the config filename.
 *
 * Deliberately NOT adopted by the irregular providers (Currency, Storefront,
 * Agent, FlowChain, Stripe) — forcing them under the base would mean more
 * override noise than the symmetry buys. See the package-tightening brief,
 * WI-6. (Payment moved onto the base in T-S-PROVIDER: its only extra is one
 * singleton, a register() override away.)
 */
abstract class DomainServiceProvider extends ServiceProvider
{
    /** The domain's directory: children return __DIR__. */
    abstract protected function domainDir(): string;

    /** Config key AND config filename (config/{key}.php). */
    protected function configKey(): string
    {
        return strtolower(basename($this->domainDir()));
    }

    /** @return array<string, class-string> Morph aliases to register. */
    protected function morphAliases(): array
    {
        return [];
    }

    /**
     * The domain's audit-log subscriber. Explicit, never derived from the
     * domain name — Shipping's is ShipmentLogSubscriber, and reflection
     * magic would guess wrong. Non-log event subscribers (e.g. Taxonomy's
     * MaintainCategoryCounts) are NOT this hook: they stay visible as
     * explicit Event::subscribe() lines in the child's boot().
     *
     * @return class-string|null
     */
    protected function logSubscriber(): ?string
    {
        return null;
    }

    /**
     * Artisan commands to register. (Named domainCommands because the
     * framework's ServiceProvider already owns commands().)
     *
     * @return list<class-string>
     */
    protected function domainCommands(): array
    {
        return [];
    }

    /** Whether config/{key}.php is publishable (tag: "{key}-config"). */
    protected function publishesConfig(): bool
    {
        return false;
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            $this->domainDir().'/config/'.$this->configKey().'.php',
            $this->configKey(),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->domainDir().'/Database/Migrations');

        $aliases = $this->morphAliases();

        if ($aliases !== []) {
            Relation::morphMap($aliases);
        }

        $subscriber = $this->logSubscriber();

        if ($subscriber !== null) {
            Event::subscribe($subscriber);
        }

        $commands = $this->domainCommands();

        if ($commands !== []) {
            $this->commands($commands);
        }

        if ($this->publishesConfig()) {
            $this->publishes([
                $this->domainDir().'/config/'.$this->configKey().'.php' => config_path($this->configKey().'.php'),
            ], $this->configKey().'-config');
        }
    }

    /**
     * Registers a scheduled-task callback gated on the domain's
     * `{key}.schedule.enabled` config (default true), deferred to
     * app.booted so the Schedule singleton exists. Called from a child's
     * boot() override — the schedule stays visible in the domain provider.
     *
     * @param  callable(Schedule): void  $schedule
     */
    protected function scheduleWhenEnabled(callable $schedule): void
    {
        if (! config($this->configKey().'.schedule.enabled', true)) {
            return;
        }

        $this->app->booted(function () use ($schedule): void {
            $schedule($this->app->make(Schedule::class));
        });
    }
}
