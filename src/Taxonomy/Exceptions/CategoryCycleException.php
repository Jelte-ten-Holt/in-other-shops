<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Exceptions;

use InOtherShops\Taxonomy\Models\Category;

final class CategoryCycleException extends TaxonomyException
{
    public static function for(Category $category, int $parentId): self
    {
        return new self(
            "Cannot move category #{$category->getKey()} ('{$category->slug}') under category #{$parentId}: that is the category itself or one of its descendants, so the tree would loop.",
        );
    }
}
