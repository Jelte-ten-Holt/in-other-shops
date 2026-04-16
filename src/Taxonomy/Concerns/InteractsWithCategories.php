<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Concerns;

use InOtherShops\Taxonomy\Taxonomy;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait InteractsWithCategories
{
    public function categories(): MorphToMany
    {
        $model = Taxonomy::category();

        return $this->morphToMany($model, 'categorizable')->withTimestamps();
    }
}
