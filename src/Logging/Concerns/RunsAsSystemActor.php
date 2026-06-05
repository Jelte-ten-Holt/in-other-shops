<?php

declare(strict_types=1);

namespace InOtherShops\Logging\Concerns;

use InOtherShops\Logging\DTOs\LogActor;
use InOtherShops\Logging\LogContext;

/**
 * For package console commands that mutate state and thereby emit audit events
 * (expiry, release, reconciliation-with-writes). Call {@see self::beginSystemAuditActor()}
 * at the top of `handle()` so every downstream audit row is attributed to the
 * command — a System actor named for its signature — instead of falling through
 * to `unknown()`. A command run by a human at the CLI is still a system process,
 * not the web-authenticated admin, so System is the right bucket.
 */
trait RunsAsSystemActor
{
    protected function beginSystemAuditActor(): void
    {
        app(LogContext::class)->setActor(LogActor::system((string) $this->getName()));
    }
}
