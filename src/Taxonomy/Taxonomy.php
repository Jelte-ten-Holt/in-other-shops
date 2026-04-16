<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy;

use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Taxonomy\Models\Tag;

final class Taxonomy
{
    /** @return class-string<Category> */
    public static function category(): string
    {
        return config('taxonomy.models.category', Category::class);
    }

    /** @return class-string<Tag> */
    public static function tag(): string
    {
        return config('taxonomy.models.tag', Tag::class);
    }
}
