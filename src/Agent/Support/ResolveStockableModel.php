<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Support;

use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Storefront\Contracts\HasStorefrontPresence;
use InvalidArgumentException;

/**
 * Resolves an agent-facing `type` key (e.g. "product") to its configured
 * storefront model class and proves the class is stockable. Composes
 * {@see ResolveBrowsableModel} (storefront-presence + unknown-type contract)
 * and adds the stockable assertion, so every stock tool shares one error
 * contract with the browsable tools.
 */
final class ResolveStockableModel
{
    public function __construct(
        private readonly ResolveBrowsableModel $resolveBrowsableModel = new ResolveBrowsableModel,
    ) {}

    /** @return class-string<HasStorefrontPresence&HasStock> */
    public function __invoke(string $type): string
    {
        $modelClass = ($this->resolveBrowsableModel)($type);

        if (! is_subclass_of($modelClass, HasStock::class)) {
            throw new InvalidArgumentException(
                "Model {$modelClass} does not implement HasStock — it is not stockable."
            );
        }

        /** @var class-string<HasStorefrontPresence&HasStock> */
        return $modelClass;
    }
}
