<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy;

use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Taxonomy\Models\Tag;

final class Taxonomy
{
    public static function category(): Category
    {
        $class = config('taxonomy.models.category', Category::class);

        return new $class;
    }

    public static function tag(): Tag
    {
        $class = config('taxonomy.models.tag', Tag::class);

        return new $class;
    }
}
