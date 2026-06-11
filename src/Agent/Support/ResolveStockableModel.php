<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Support;

use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Storefront\Contracts\HasStorefrontPresence;
use InvalidArgumentException;

/**
 * Resolves an agent-facing `type` key (e.g. "product") to its configured
 * storefront model class and proves the class is stockable. Shared by every
 * stock tool so the error contract stays identical across them.
 */
final class ResolveStockableModel
{
    /** @return class-string<HasStorefrontPresence&HasStock> */
    public function __invoke(string $type): string
    {
        /** @var array<string, class-string> $models */
        $models = config('storefront.models', []);

        if (! isset($models[$type])) {
            $available = array_keys($models);

            throw new InvalidArgumentException(
                'Unknown type "'.$type.'". Available: '.
                    (count($available) > 0 ? implode(', ', $available) : '(none configured)').'.'
            );
        }

        $modelClass = $models[$type];

        if (! is_subclass_of($modelClass, HasStorefrontPresence::class)) {
            throw new InvalidArgumentException(
                "Model {$modelClass} does not implement HasStorefrontPresence."
            );
        }

        if (! is_subclass_of($modelClass, HasStock::class)) {
            throw new InvalidArgumentException(
                "Model {$modelClass} does not implement HasStock — it is not stockable."
            );
        }

        return $modelClass;
    }
}
