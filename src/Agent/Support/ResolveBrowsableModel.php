<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Support;

use InOtherShops\Storefront\Contracts\HasStorefrontPresence;
use InvalidArgumentException;

/**
 * Resolves an agent-facing `type` key (e.g. "product") to its configured
 * storefront model class, proving the class has storefront presence. The single
 * resolver shared by every browsable/stock tool, so the unknown-type and
 * wrong-contract error contract stays identical across them.
 */
final class ResolveBrowsableModel
{
    /** @return class-string<HasStorefrontPresence> */
    public function __invoke(string $type): string
    {
        /** @var array<string, class-string> $models */
        $models = config('storefront.models', []);

        if (! isset($models[$type])) {
            $available = array_keys($models);

            throw new InvalidArgumentException(
                'Unknown browsable type "'.$type.'". Available: '.
                    (count($available) > 0 ? implode(', ', $available) : '(none configured)').'.'
            );
        }

        $modelClass = $models[$type];

        if (! is_subclass_of($modelClass, HasStorefrontPresence::class)) {
            throw new InvalidArgumentException(
                "Model {$modelClass} does not implement HasStorefrontPresence."
            );
        }

        return $modelClass;
    }
}
