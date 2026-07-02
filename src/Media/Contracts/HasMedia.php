<?php

declare(strict_types=1);

namespace InOtherShops\Media\Contracts;

use InOtherShops\Media\Models\Media;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

interface HasMedia
{
    public function media(): MorphToMany;

    public function firstMedia(?string $collection = null): ?Media;

    public function coverImage(): ?Media;
}
