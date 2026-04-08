<?php

declare(strict_types=1);

namespace InOtherShops\Translation\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;

interface Translatable
{
    /** @return array<string> */
    public function translatableFields(): array;

    public function translations(): MorphMany;
}
