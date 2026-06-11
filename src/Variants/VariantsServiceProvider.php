<?php

declare(strict_types=1);

namespace InOtherShops\Variants;

use InOtherShops\Support\DomainServiceProvider;

final class VariantsServiceProvider extends DomainServiceProvider
{
    protected function domainDir(): string
    {
        return __DIR__;
    }

    protected function morphAliases(): array
    {
        return [
            'option' => Variants::option(),
            'option_value' => Variants::optionValue(),
            'variant' => Variants::variant(),
        ];
    }

    protected function publishesConfig(): bool
    {
        return true;
    }
}
