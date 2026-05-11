<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use InOtherShops\Taxonomy\Models\Category;

final readonly class CategoryMoved
{
    use Dispatchable;

    public function __construct(
        public Category $category,
        public ?int $oldParentId,
        public ?int $newParentId,
    ) {}
}
