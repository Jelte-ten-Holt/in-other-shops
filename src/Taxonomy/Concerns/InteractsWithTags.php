<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Concerns;

use InOtherShops\Taxonomy\Taxonomy;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait InteractsWithTags
{
    public function tags(): MorphToMany
    {
        $model = Taxonomy::tag();

        return $this->morphToMany($model, 'taggable')->withTimestamps();
    }
}
