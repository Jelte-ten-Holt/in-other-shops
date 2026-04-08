<?php

declare(strict_types=1);

namespace InOtherShops\Storefront\Controllers;

use InOtherShops\Storefront\Actions\ListBrowsables;
use InOtherShops\Storefront\Resources\BrowsableResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class BrowsableListController
{
    public function __invoke(Request $request, ListBrowsables $action): AnonymousResourceCollection
    {
        /** @var class-string $modelClass */
        $modelClass = $request->route()->defaults['browsable_model'];

        $paginator = $action($modelClass, $request);

        return BrowsableResource::collection($paginator);
    }
}
