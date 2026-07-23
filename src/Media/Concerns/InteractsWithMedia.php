<?php

declare(strict_types=1);

namespace InOtherShops\Media\Concerns;

use InOtherShops\Media\Media;
use InOtherShops\Media\Models\Media as MediaModel;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait InteractsWithMedia
{
    public function media(): MorphToMany
    {
        $mediaModel = Media::media();
        $mediableModel = Media::mediable();

        return $this->morphToMany($mediaModel, 'mediable')
            ->using($mediableModel)
            ->withPivot('collection', 'position', 'is_cover')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function firstMedia(?string $collection = null): ?MediaModel
    {
        // When the caller already eager-loaded `media` (the storefront does
        // `with('media')` for exactly this), resolve from the loaded collection
        // instead of issuing a fresh pivot query per model (SCALE-4). The
        // relation orders by pivot position, so the loaded collection arrives
        // in the same order the query path would return.
        if ($this->relationLoaded('media')) {
            return $this->media->first(
                fn (MediaModel $media): bool => $collection === null
                    || $media->pivot->collection === $collection,
            );
        }

        $query = $this->media();

        if ($collection !== null) {
            $query = $query->wherePivot('collection', $collection);
        }

        return $query->first();
    }

    public function coverImage(): ?MediaModel
    {
        // In-memory equivalent of the query path below (SCALE-4): first row
        // flagged is_cover (any collection, position order), falling back to
        // the first item of the `images` collection via firstMedia(), which
        // does its own relationLoaded() check.
        if ($this->relationLoaded('media')) {
            return $this->media->first(fn (MediaModel $media): bool => (bool) $media->pivot->is_cover)
                ?? $this->firstMedia('images');
        }

        return $this->media()->wherePivot('is_cover', true)->first()
            ?? $this->firstMedia('images');
    }
}
