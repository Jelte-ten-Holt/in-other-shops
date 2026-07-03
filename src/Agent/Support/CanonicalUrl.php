<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Support;

use RuntimeException;

final class CanonicalUrl
{
    /**
     * Fail closed at boot: with OAuth enabled, a blank canonical_url would
     * make resource() fall through to url(), which derives its host from
     * APP_URL or — absent that — the incoming Host header. That value becomes
     * the RFC 8707 resource audience and the RFC 9728 issuer, so a spoofed
     * Host header could steer the advertised audience/issuer. Refuse to boot
     * rather than serve attacker-influenceable OAuth metadata.
     */
    public static function assertConfiguredForOauth(): void
    {
        if (! (bool) config('agent.auth.oauth.enabled', false)) {
            return;
        }

        $configured = config('agent.canonical_url');

        if (! is_string($configured) || trim($configured) === '') {
            throw new RuntimeException(
                'agent.canonical_url must be set when agent OAuth is enabled '
                .'(AGENT_CANONICAL_URL). Refusing to derive the OAuth resource/issuer '
                .'from the request host.'
            );
        }
    }

    public static function resource(): string
    {
        $configured = config('agent.canonical_url');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim(url((string) config('agent.route.path', '/mcp')), '/');
    }

    public static function issuer(): string
    {
        $resource = self::resource();
        $parsed = parse_url($resource);

        if (! isset($parsed['scheme'], $parsed['host'])) {
            return rtrim(url('/'), '/');
        }

        $origin = $parsed['scheme'].'://'.$parsed['host'];

        if (isset($parsed['port'])) {
            $origin .= ':'.$parsed['port'];
        }

        return $origin;
    }
}
