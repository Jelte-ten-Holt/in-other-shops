<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Tools;

use InOtherShops\Agent\AgentTool;
use InOtherShops\Agent\Support\ResolveStockableModel;
use InOtherShops\Inventory\Contracts\HasStock;

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
        return 'Return availability for a browsable (product, bundle, etc.) identified by type + slug. Admin callers get the exact stock_level; non-admin callers get the in_stock flag only.';
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
        $modelClass = (new ResolveStockableModel)($type);

        $model = $modelClass::browseQuery()
            ->where((new $modelClass)->getBrowsableRouteKeyName(), $slug)
            ->first();

        $target = ['type' => $type, 'slug' => $slug];

        if ($model === null) {
            return $this->failure('not_found', "No {$type} with slug '{$slug}'.", $target);
        }

        /** @var HasStock $model */
        $data = ['in_stock' => $model->isInStock()];

        // Exact quantities are business-sensitive (restock urgency, sell-through);
        // the storefront only ever shows the boolean. Admin-only.
        if ($this->isAdmin()) {
            $data['stock_level'] = $model->stockLevel();
        }

        return [
            'ok' => true,
            'target' => $target,
            'data' => $data,
        ];
    }
}
