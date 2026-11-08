<?php

declare(strict_types=1);

namespace InOtherShops\Variants;

use InOtherShops\Support\DomainServiceProvider;
use InOtherShops\Variants\Models\Option;
use InOtherShops\Variants\Models\OptionValue;

final class VariantsServiceProvider extends DomainServiceProvider
{
    protected function domainDir(): string
    {
        return __DIR__;
    }

    protected function morphAliases(): array
    {
        return [
            'option' => Option::class,
            'option_value' => OptionValue::class,
        ];
    }

    protected function publishesConfig(): bool
    {
        return false;
    }
}
