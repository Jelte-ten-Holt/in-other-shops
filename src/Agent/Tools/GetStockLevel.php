<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Tools;

use InOtherShops\Agent\AgentTool;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Storefront\Contracts\HasStorefrontPresence;
use InvalidArgumentException;

final class GetStockLevel extends AgentTool
{
    public static function identifier(): string
    {
        return 'get_stock_level';
    }

    public static function displayName(): string
    {
        return 'Get stock level';
    }

    public function description(): string
    {
        return 'Return the current stock level and availability for a browsable (product, bundle, etc.) identified by type + slug.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'description' => 'Browsable type key from config("storefront.models"). Must resolve to a model that implements HasStock.',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Slug of the stockable browsable.',
                ],
            ],
            'required' => ['type', 'slug'],
            'additionalProperties' => false,
        ];
    }

    public function __invoke(array $arguments): array
    {
        $type = (string) ($arguments['type'] ?? '');
        $slug = (string) ($arguments['slug'] ?? '');
        $modelClass = $this->resolveStockableClass($type);

        $model = $modelClass::browseQuery()
            ->where((new $modelClass)->getBrowsableRouteKeyName(), $slug)
            ->first();

        $target = ['type' => $type, 'slug' => $slug];

        if ($model === null) {
            return [
                'ok' => false,
                'target' => $target,
                'error' => [
                    'code' => 'not_found',
                    'message' => "No {$type} with slug '{$slug}'.",
                ],
            ];
        }

        /** @var HasStock $model */
        return [
            'ok' => true,
            'target' => $target,
            'data' => [
                'stock_level' => $model->stockLevel(),
                'in_stock' => $model->isInStock(),
            ],
        ];
    }

    /** @return class-string<HasStorefrontPresence&HasStock> */
    private function resolveStockableClass(string $type): string
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
