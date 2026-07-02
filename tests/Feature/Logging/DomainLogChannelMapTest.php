<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Logging;

use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * T-A5 — the shipped domain-log config must map every channel a subscriber
 * writes to, or that channel's audit lines silently route to the `daily`
 * default instead of their own file.
 *
 * This runs against the SHIPPED config (no defineEnvironment override), unlike
 * AuditPipelineRowTest which routes channels to the database handler — that
 * override masked the missing `shipping` mapping.
 */
final class DomainLogChannelMapTest extends TestCase
{
    #[Test]
    public function the_shipped_config_maps_every_subscriber_channel_to_its_own_file_channel(): void
    {
        /** @var array<string, array<int, array{handler: class-string, with: array{channel?: string}}>> $channels */
        $channels = config('domain-log.channels');

        // `shipping` was the omission (routed to `daily`); assert it and its
        // siblings are each mapped to a same-named dedicated file channel.
        // `pricing` is deliberately absent — it streams to `commerce` by design.
        foreach (['commerce', 'inventory', 'purchasing', 'shipping', 'payment', 'agent', 'flowchain'] as $channel) {
            $this->assertArrayHasKey($channel, $channels, "The `{$channel}` channel is unmapped and would fall to the daily default.");
            $this->assertSame($channel, $channels[$channel][0]['with']['channel']);
        }
    }
}
