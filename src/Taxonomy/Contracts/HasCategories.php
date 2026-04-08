<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphToMany;

interface HasCategories
{
    public function categories(): MorphToMany;
}
