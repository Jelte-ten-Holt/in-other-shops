<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphToMany;

interface HasTags
{
    public function tags(): MorphToMany;
}
