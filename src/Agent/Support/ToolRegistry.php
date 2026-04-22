<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use InOtherShops\Agent\Contracts\AgentToolContract;

final class ToolRegistry
{
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
        return config('agent.tools', []);
    }
}
