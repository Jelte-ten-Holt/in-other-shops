<?php

declare(strict_types=1);

namespace InOtherShops\Media\Concerns;

use InOtherShops\Media\Media;
use InOtherShops\Media\Models\Media as MediaModel;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;

trait InteractsWithMedia
{
    public function media(): MorphToMany
    {
        $mediaModel = Media::media();
        $mediableModel = Media::mediable();

        return $this->morphToMany($mediaModel::class, 'mediable')
            ->using($mediableModel::class)
            ->withPivot('collection', 'position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * @return Collection<int, MediaModel>
     */
    public function mediaInCollection(string $collection): Collection
    {
        return $this->media()->wherePivot('collection', $collection)->get();
    }

    public function firstMedia(?string $collection = null): ?MediaModel
    {
        $query = $this->media();

        if ($collection !== null) {
            $query = $query->wherePivot('collection', $collection);
        }

        return $query->first();
    }

    public function coverImage(): ?MediaModel
    {
        return $this->firstMedia('images');
    }
}
