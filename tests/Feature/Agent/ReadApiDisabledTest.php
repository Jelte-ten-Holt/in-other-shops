<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shipped config, untouched: the read API is off and its routes do not
 * exist, so a shop exposes nothing until it turns it on with a bearer.
 */
final class ReadApiDisabledTest extends TestCase
{
    #[Test]
    public function the_read_api_is_off_unless_a_shop_turns_it_on(): void
    {
        $this->assertFalse(config('agent.read.enabled'));

        $this->getJson('/api/read/v1/payments?until=2026-12-31T00:00:00Z')->assertNotFound();
    }
}
