<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Events;

final class DynamicClientRegistered
{
    /**
     * @param  array<int, string>  $redirectUris
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientName,
        public readonly array $redirectUris,
        public readonly bool $isConfidential,
    ) {}
}
