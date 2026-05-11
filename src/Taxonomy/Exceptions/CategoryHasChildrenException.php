<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Exceptions;

use InOtherShops\Taxonomy\Models\Category;

final class CategoryHasChildrenException extends TaxonomyException
{
    public static function for(Category $category): self
    {
        return new self(
            "Cannot delete category #{$category->getKey()} ('{$category->slug}') while it has child categories. Reparent or delete its children first.",
        );
    }
}
