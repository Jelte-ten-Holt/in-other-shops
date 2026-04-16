<?php

declare(strict_types=1);

namespace InOtherShops\Storefront\Actions;

use InOtherShops\Storefront\Concerns\ResolvesEagerLoading;
use InOtherShops\Storefront\Contracts\HasStorefrontPresence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ShowBrowsable
{
    use ResolvesEagerLoading;

    /**
     * @param  class-string<HasStorefrontPresence>  $modelClass
     */
    public function __invoke(string $modelClass, string $slug): ?Model
    {
        $query = $modelClass::browseQuery();

        $this->eagerLoadForContracts($query, $modelClass);

        return $this->findBySlug($query, $modelClass, $slug);
    }

    /**
     * @param  class-string<HasStorefrontPresence>  $modelClass
     */
    private function findBySlug(Builder $query, string $modelClass, string $slug): ?Model
    {
        $routeKeyName = (new $modelClass)->getBrowsableRouteKeyName();

        return $query->where($routeKeyName, $slug)->first();
    }
}
