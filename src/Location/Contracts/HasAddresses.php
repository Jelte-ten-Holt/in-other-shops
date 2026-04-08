<?php

declare(strict_types=1);

namespace InOtherShops\Location\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;

interface HasAddresses
{
    public function addresses(): MorphMany;
}
