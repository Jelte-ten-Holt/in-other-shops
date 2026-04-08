<?php

declare(strict_types=1);

namespace InOtherShops\Storefront\Controllers;

use InOtherShops\Storefront\Actions\ShowBrowsable;
use InOtherShops\Storefront\Resources\BrowsableResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BrowsableShowController
{
    public function __invoke(string $slug, Request $request, ShowBrowsable $action): BrowsableResource|JsonResponse
    {
        /** @var class-string $modelClass */
        $modelClass = $request->route()->defaults['browsable_model'];

        $model = $action($modelClass, $slug);

        if ($model === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return new BrowsableResource($model);
    }
}
