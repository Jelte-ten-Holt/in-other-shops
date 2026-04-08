<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Actions;

use InOtherShops\Taxonomy\Contracts\HasTags;
use InOtherShops\Taxonomy\Events\TagAttached;
use InOtherShops\Taxonomy\Models\Tag;
use Illuminate\Database\Eloquent\Model;

final class AttachTag
{
    /**
     * @param  Model&HasTags  $model
     */
    public function __invoke(Model $model, Tag $tag): void
    {
        $model->tags()->attach($tag);

        TagAttached::dispatch($model, $tag);
    }
}
