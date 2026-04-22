<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Support;

final class CanonicalUrl
{
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
