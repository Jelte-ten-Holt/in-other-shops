<?php

declare(strict_types=1);

namespace InOtherShops\Storefront\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface Browsable
{
    public function getBrowsableName(): string;

    public function getBrowsableSlug(): string;

    public function getBrowsableDescription(): ?string;

    public function getBrowsableRouteKeyName(): string;

    public static function browseQuery(): Builder;
}
