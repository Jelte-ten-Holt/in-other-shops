<?php

declare(strict_types=1);

namespace InOtherShops\Translation;

use InOtherShops\Support\DomainServiceProvider;

final class TranslationServiceProvider extends DomainServiceProvider
{
    protected function domainDir(): string
    {
        return __DIR__;
    }

    protected function morphAliases(): array
    {
        return [
            'translation' => Translation::translation(),
            'locale_group' => Translation::localeGroup(),
        ];
    }
}
