<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Concerns;

use InOtherShops\Variants\Variants;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait InteractsWithVariants
{
    public function variants(): MorphMany
    {
        return $this->morphMany(Variants::variant(), 'variantable')->orderBy('position');
    }

    public function options(): MorphToMany
    {
        return $this->morphToMany(Variants::option(), 'optionable')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function hasVariants(): bool
    {
        if ($this->relationLoaded('variants')) {
            return $this->variants->isNotEmpty();
        }

        return $this->variants()->exists();
    }
}
