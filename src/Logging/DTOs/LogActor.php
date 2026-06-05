<?php

declare(strict_types=1);

namespace InOtherShops\Logging\DTOs;

use InOtherShops\Logging\Enums\LogActorType;

/**
 * Who an audit-log row is attributed to. Set once at a request/job boundary and
 * carried into every downstream entry via {@see \InOtherShops\Logging\LogContext}
 * — so a deep event (`StockAdjusted`) need not thread an actor through its
 * signature; the boundary already established it. A few operations know their
 * actor better than the ambient boundary does (refunds) and carry one explicitly
 * on the {@see LogEntry}, which overrides the ambient one.
 *
 * Mirrors the vocabulary of {@see \InOtherShops\Commerce\Order\DTOs\RefundActor}
 * (business data on the `refunds` row) so the audit actor derived from a refund
 * stays consistent with it — see the brief, §4.
 *
 * `unknown()` is the loud default: a row attributed to it means no boundary set
 * an actor, which should be ~0 rows in production and is a tripwire if not.
 */
final readonly class LogActor
{
    public function __construct(
        public LogActorType $type,
        public ?string $id,
        public string $label,
    ) {}

    /** An authenticated admin or customer. */
    public static function user(string $id, string $label): self
    {
        return new self(LogActorType::User, $id, $label);
    }

    /** An unauthenticated request (anonymous storefront/guest checkout). */
    public static function guest(): self
    {
        return new self(LogActorType::User, null, 'guest');
    }

    /** A payment gateway acting via webhook/callback (id and label = gateway name). */
    public static function gateway(string $name): self
    {
        return new self(LogActorType::Gateway, $name, $name);
    }

    /** A scheduled command or internal process (id and label = its signature). */
    public static function system(string $source): self
    {
        return new self(LogActorType::System, $source, $source);
    }

    /** The agent/MCP connector, identified by its resolved client. */
    public static function agent(string $id, string $label): self
    {
        return new self(LogActorType::Agent, $id, $label);
    }

    /** No boundary established an actor — recorded loudly, never as a null hole. */
    public static function unknown(): self
    {
        return new self(LogActorType::System, null, 'unknown');
    }
}
