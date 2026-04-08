<?php

declare(strict_types=1);

namespace InOtherShops\Media\Contracts;

use InOtherShops\Media\Models\Media;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;

interface HasMedia
{
    public function media(): MorphToMany;

    /**
     * @return Collection<int, Media>
     */
    public function mediaInCollection(string $collection): Collection;

    public function firstMedia(?string $collection = null): ?Media;

    public function coverImage(): ?Media;
}
