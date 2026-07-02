<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use InOtherShops\Agent\AgentServiceProvider;
use InOtherShops\Agent\Support\CanonicalUrl;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * T-SEC4: with OAuth enabled and no pinned canonical URL, the resource/issuer
 * would be derived from APP_URL or the request Host header — attacker-influenceable
 * OAuth metadata. The provider must refuse to boot instead.
 */
final class CanonicalUrlBootGuardTest extends TestCase
{
    #[Test]
    public function oauth_enabled_with_blank_canonical_url_refuses_to_boot(): void
    {
        config()->set('agent.auth.oauth.enabled', true);
        config()->set('agent.canonical_url', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/canonical_url must be set/');

        (new AgentServiceProvider($this->app))->boot();
    }

    #[Test]
    public function oauth_enabled_with_whitespace_canonical_url_refuses_to_boot(): void
    {
        config()->set('agent.auth.oauth.enabled', true);
        config()->set('agent.canonical_url', '   ');

        $this->expectException(RuntimeException::class);

        CanonicalUrl::assertConfiguredForOauth();
    }

    #[Test]
    public function oauth_enabled_with_pinned_canonical_url_boots(): void
    {
        config()->set('agent.auth.oauth.enabled', true);
        config()->set('agent.canonical_url', 'https://agent.example.test/mcp');

        CanonicalUrl::assertConfiguredForOauth();

        $this->assertTrue(true); // no throw is the assertion
    }

    #[Test]
    public function oauth_disabled_needs_no_canonical_url(): void
    {
        config()->set('agent.auth.oauth.enabled', false);
        config()->set('agent.canonical_url', null);

        CanonicalUrl::assertConfiguredForOauth();

        $this->assertTrue(true); // no throw is the assertion
    }
}
