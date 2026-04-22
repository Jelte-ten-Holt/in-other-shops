<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Tools;

use InOtherShops\Agent\AgentTool;
use InOtherShops\Inventory\Actions\AdjustStock as AdjustStockAction;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Storefront\Contracts\HasStorefrontPresence;
use InvalidArgumentException;

/**
 * First mutation tool. Adds stock to a browsable. `delta` is schema-gated at
 * `minimum: 1` AND re-checked in __invoke — the schema keeps well-behaved
 * callers out, the guard keeps the rest out. Negative-delta / removal flows
 * are out of scope for v1; a separate tool would make the distinction
 * explicit rather than widening this one's contract.
 */
final class AdjustStock extends AgentTool
{
    /** Subset of StockMovementReason suitable for positive-delta adjustments. */
    private const array ALLOWED_REASONS = [
        'received',
        'restock',
        'adjusted',
    ];

    public function __construct(private readonly AdjustStockAction $adjustStock) {}

    public static function identifier(): string
    {
        return 'adjust_stock';
    }

    public static function displayName(): string
    {
        return 'Adjust stock';
    }

    public function description(): string
    {
        return 'Add stock to a browsable (product, bundle, etc.) identified by type + slug. Positive delta only; writes one StockMovement and dispatches StockAdjusted.';
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
                'delta' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Units to add. Must be >= 1. Use a separate tool for removals (not yet exposed).',
                ],
                'reason' => [
                    'type' => 'string',
                    'enum' => self::ALLOWED_REASONS,
                    'description' => 'Why the adjustment is being made. Defaults to "restock".',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Optional free-text note attached to the StockMovement for audit context.',
                ],
            ],
            'required' => ['type', 'slug', 'delta'],
            'additionalProperties' => false,
        ];
    }

    public function __invoke(array $arguments): array
    {
        $type = (string) ($arguments['type'] ?? '');
        $slug = (string) ($arguments['slug'] ?? '');
        $delta = (int) ($arguments['delta'] ?? 0);
        $reason = $this->resolveReason($arguments['reason'] ?? null);
        $description = $this->resolveDescription($arguments['description'] ?? null);

        $this->guardDelta($delta);

        $modelClass = $this->resolveStockableClass($type);

        $target = ['type' => $type, 'slug' => $slug];

        $model = $modelClass::browseQuery()
            ->where((new $modelClass)->getBrowsableRouteKeyName(), $slug)
            ->first();

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

        /** @var \Illuminate\Database\Eloquent\Model&HasStock $model */
        $previous = $model->stockLevel();

        $movement = ($this->adjustStock)(
            stockable: $model,
            quantity: $delta,
            reason: $reason,
            description: $description,
            source: 'agent',
        );

        return [
            'ok' => true,
            'target' => $target,
            'data' => [
                'previous_stock_level' => $previous,
                'stock_level' => $previous + $delta,
                'delta_applied' => $delta,
                'reason' => $reason->value,
                'movement_id' => $movement->getKey(),
            ],
        ];
    }

    private function guardDelta(int $delta): void
    {
        if ($delta < 1) {
            throw new InvalidArgumentException(
                "Delta must be >= 1; got {$delta}. This tool only adds stock — use a removal tool (not yet exposed) for decrements."
            );
        }
    }

    private function resolveReason(mixed $raw): StockMovementReason
    {
        if ($raw === null || $raw === '') {
            return StockMovementReason::Restock;
        }

        if (! is_string($raw) || ! in_array($raw, self::ALLOWED_REASONS, true)) {
            throw new InvalidArgumentException(
                'Invalid reason "'.(string) $raw.'". Allowed: '.implode(', ', self::ALLOWED_REASONS).'.'
            );
        }

        return StockMovementReason::from($raw);
    }

    private function resolveDescription(mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return $raw;
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
