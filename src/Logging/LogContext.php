<?php

declare(strict_types=1);

namespace InOtherShops\Logging;

use InOtherShops\Logging\DTOs\LogActor;

/**
 * Ambient, boundary-scoped audit context. Registered as a singleton, so the
 * actor must be set per request/job at the boundary and cleared/overwritten —
 * never carried across. Every audit `*LogSubscriber` is synchronous (runs
 * in-request) and inherits the boundary actor; if any audit-writing listener is
 * ever made `ShouldQueue`, it must capture the actor at dispatch and re-establish
 * it in the job, or it would inherit a worker's stale context (brief, §5).
 */
final class LogContext
{
    /** @var array<string, mixed> */
    private array $context = [];

    private ?LogActor $actor = null;

    public function set(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->context;
    }

    public function setActor(LogActor $actor): void
    {
        $this->actor = $actor;
    }

    public function actor(): ?LogActor
    {
        return $this->actor;
    }

    public function forgetActor(): void
    {
        $this->actor = null;
    }
}
