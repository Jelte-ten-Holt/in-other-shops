<?php

declare(strict_types=1);

namespace InOtherShops\Storefront\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait InteractsWithStorefrontPresence
{
    public function getBrowsableName(): string
    {
        return $this->name;
    }

    public function getBrowsableSlug(): string
    {
        return $this->slug;
    }

    public function getBrowsableDescription(): ?string
    {
        return $this->description;
    }

    public function getBrowsableRouteKeyName(): string
    {
        return 'slug';
    }

    public static function browseQuery(): Builder
    {
        return static::query()
            ->where('is_active', true)
            ->where('published_at', '<=', now());
    }
}
