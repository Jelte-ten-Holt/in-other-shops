<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Events;

use InOtherShops\Taxonomy\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class TagAttached
{
    use Dispatchable;

    public function __construct(
        public Model $model,
        public Tag $tag,
    ) {}
}
