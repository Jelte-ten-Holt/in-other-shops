<?php

declare(strict_types=1);

namespace InOtherShops\Location;

use InOtherShops\Support\DomainServiceProvider;

final class LocationServiceProvider extends DomainServiceProvider
{
    protected function domainDir(): string
    {
        return __DIR__;
    }

    protected function morphAliases(): array
    {
        return [
            'address' => Location::address(),
        ];
    }
}
