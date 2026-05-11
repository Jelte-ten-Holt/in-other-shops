<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class CategoryDeleted
{
    use Dispatchable;

    /**
     * @param  array<string, int>  $counts  Snapshot of subtree counts keyed by morph_alias,
     *                                      captured before the row was deleted so ancestor
     *                                      decrements can be applied without re-querying.
     */
    public function __construct(
        public int $categoryId,
        public ?int $parentId,
        public array $counts,
    ) {}
}
