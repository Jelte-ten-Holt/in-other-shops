<?php

declare(strict_types=1);

namespace InOtherShops\Media\Enums;

enum MediaType: string
{
    case Upload = 'upload';
    case External = 'external';
    case Embed = 'embed';
}
