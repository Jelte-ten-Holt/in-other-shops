<?php

declare(strict_types=1);

namespace InOtherShops\Tax;

use InOtherShops\Support\DomainServiceProvider;

final class TaxServiceProvider extends DomainServiceProvider
{
    protected function domainDir(): string
    {
        return __DIR__;
    }

    protected function morphAliases(): array
    {
        return [
            'tax_rate' => Tax::taxRate(),
        ];
    }

    protected function publishesConfig(): bool
    {
        return true;
    }
}
