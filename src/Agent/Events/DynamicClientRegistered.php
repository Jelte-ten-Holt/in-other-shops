<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class DynamicClientRegistered
{
    use Dispatchable;

    /**
     * @param  array<int, string>  $redirectUris
     */
    public function __construct(
        public string $clientId,
        public string $clientName,
        public array $redirectUris,
        public bool $isConfidential,
    ) {}
}
