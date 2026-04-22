<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use InOtherShops\Agent\Contracts\AgentToolContract;
use InOtherShops\Agent\Tools\AdjustStock;
use InOtherShops\Agent\Tools\BrowseCatalog;
use InOtherShops\Agent\Tools\GetStockLevel;
use InOtherShops\Agent\Tools\ListCategories;
use InOtherShops\Agent\Tools\ListOrders;
use InOtherShops\Agent\Tools\ListTags;
use InOtherShops\Agent\Tools\Ping;
use InOtherShops\Agent\Tools\ShowBrowsable;
use InOtherShops\Agent\Tools\ShowOrder;

final class ToolRegistry
{
    /**
     * Tools shipped by this package. Consumers extend the registry by listing
     * their own tool classes in `config('agent.tools')`; those are concatenated
     * onto this list. Kept as a class constant (not config) so that Laravel's
     * shallow `mergeConfigFrom` cannot silently wipe them when a consumer
     * publishes its own `config/agent.php`.
     *
     * @var array<int, class-string<AgentToolContract>>
     */
    private const array PACKAGE_TOOLS = [
        Ping::class,
        BrowseCatalog::class,
        ShowBrowsable::class,
        ListCategories::class,
        ListTags::class,
        ListOrders::class,
        ShowOrder::class,
        GetStockLevel::class,
        AdjustStock::class,
    ];

    /** @var array<string, AgentToolContract> */
    private array $tools = [];

    public function __construct(
        private readonly Application $app,
    ) {
        foreach ($this->classes() as $class) {
            $instance = $this->app->make($class);
            $this->tools[$class::identifier()] = $instance;
        }
    }

    /** @return Collection<string, AgentToolContract> */
    public function all(): Collection
    {
        return collect($this->tools);
    }

    public function find(string $identifier): ?AgentToolContract
    {
        return $this->tools[$identifier] ?? null;
    }

    /** @return array<int, class-string<AgentToolContract>> */
    public function classes(): array
    {
        /** @var array<int, class-string<AgentToolContract>> $consumerTools */
        $consumerTools = config('agent.tools', []);

        return [...self::PACKAGE_TOOLS, ...$consumerTools];
    }
}
