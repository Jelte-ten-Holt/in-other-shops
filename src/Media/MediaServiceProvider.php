<?php

declare(strict_types=1);

namespace InOtherShops\Media;

use InOtherShops\Media\Commands\GenerateMediaVariantsCommand;
use InOtherShops\Support\DomainServiceProvider;

final class MediaServiceProvider extends DomainServiceProvider
{
    protected function domainDir(): string
    {
        return __DIR__;
    }

    protected function morphAliases(): array
    {
        return [
            'media' => Media::media(),
        ];
    }

    protected function domainCommands(): array
    {
        return [GenerateMediaVariantsCommand::class];
    }
}
