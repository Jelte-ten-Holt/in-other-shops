<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Actions;

use InOtherShops\Taxonomy\Contracts\HasTags;
use InOtherShops\Taxonomy\Events\TagDetached;
use InOtherShops\Taxonomy\Models\Tag;
use Illuminate\Database\Eloquent\Model;

final class DetachTag
{
    public function __invoke(Model&HasTags $model, Tag $tag): void
    {
        $model->tags()->detach($tag);

        TagDetached::dispatch($model, $tag);
    }
}
