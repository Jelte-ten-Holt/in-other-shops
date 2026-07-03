<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Tools;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InOtherShops\Agent\AgentTool;
use InOtherShops\Agent\Support\PaginationParams;
use InOtherShops\Agent\Support\ResolveStockableModel;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Storefront\Contracts\HasStorefrontPresence;
use InvalidArgumentException;

/**
 * Bulk stock readout for a browsable type. The natural pair to BrowseCatalog
 * when you need quantities instead of catalog metadata — and the way to pull
 * a low-stock report in one call instead of N × get_stock_level.
 *
 * `low_threshold` filters at SQL level via the stock_items relation, so only
 * items that are actively tracking stock surface in low-stock mode. Items
 * without a stock_items row (typically non-tracking, e.g. digital products)
 * are excluded from low-stock filtering — they would always read as 0 stock
 * but isInStock=true, which would pollute the report.
 */
final class ListStockLevels extends AgentTool
{
    private const int DEFAULT_PER_PAGE = 50;

    private const int MAX_PER_PAGE = 200;

    public static function identifier(): string
    {
        return 'list_stock_levels';
    }

    public static function displayName(): string
    {
        return 'List stock levels';
    }

    public function description(): string
    {
        return 'Bulk availability readout for a browsable type. Returns slug, name, in_stock, and tracks_stock for each item from browseQuery(); admin callers additionally get the exact stock_level. low_threshold (restrict to items at or below a stock level, sorted most-urgent-first) is admin-only — it is a quantity oracle.';
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
                'low_threshold' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => 'Admin-only. When set, returns only items with stock_level <= this number (sorted ascending). Items with no stock_items row are excluded.',
                ],
                'page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                ],
                'per_page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_PER_PAGE,
                ],
            ],
            'required' => ['type'],
            'additionalProperties' => false,
        ];
    }

    public function __invoke(array $arguments): array
    {
        $type = (string) ($arguments['type'] ?? '');
        $modelClass = (new ResolveStockableModel)($type);

        $lowThreshold = $this->intArg($arguments, 'low_threshold');

        // low_threshold is a quantity oracle: a non-admin could binary-search
        // exact stock levels through it even with stock_level omitted from
        // the shape. Admin-only, like the quantities themselves.
        if ($lowThreshold !== null && ! $this->isAdmin()) {
            return $this->failure(
                'forbidden',
                'low_threshold requires the admin scope or the operator bearer token.',
                ['type' => $type, 'low_threshold' => $lowThreshold],
            );
        }

        $pagination = PaginationParams::fromArguments($arguments, self::MAX_PER_PAGE, self::DEFAULT_PER_PAGE);

        $paginator = $this->buildQuery($modelClass, $lowThreshold)
            ->paginate(perPage: $pagination->perPage, page: $pagination->page);

        $isAdmin = $this->isAdmin();

        return [
            'ok' => true,
            'target' => ['type' => $type, 'low_threshold' => $lowThreshold],
            'data' => $paginator->getCollection()
                ->map(fn (Model $model): array => $this->shape($model, $isAdmin))
                ->all(),
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    /**
     * @param  class-string<HasStorefrontPresence&HasStock>  $modelClass
     * @return Builder<Model>
     */
    private function buildQuery(string $modelClass, ?int $lowThreshold): Builder
    {
        $query = $modelClass::browseQuery()->with('stockItem');

        if ($lowThreshold !== null) {
            $instance = new $modelClass;
            $table = $instance->getTable();
            $stockItemsTable = (new (Inventory::stockItem()))->getTable();
            $morphClass = $instance->getMorphClass();

            $query
                ->join($stockItemsTable, function ($join) use ($stockItemsTable, $table, $morphClass): void {
                    $join->on("{$stockItemsTable}.stockable_id", '=', "{$table}.id")
                        ->where("{$stockItemsTable}.stockable_type", '=', $morphClass);
                })
                ->where("{$stockItemsTable}.stock_level", '<=', $lowThreshold)
                ->select("{$table}.*")
                ->orderBy("{$stockItemsTable}.stock_level", 'asc');
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function shape(Model $model, bool $includeQuantities): array
    {
        /** @var HasStorefrontPresence&HasStock $model */
        $shape = [
            'slug' => $model->getBrowsableSlug(),
            'name' => $model->getBrowsableName(),
            'in_stock' => $model->isInStock(),
            'tracks_stock' => $model->tracksStock(),
        ];

        if ($includeQuantities) {
            $shape['stock_level'] = $model->stockLevel();
        }

        return $shape;
    }

    /** @param array<string, mixed> $arguments */
    private function intArg(array $arguments, string $key): ?int
    {
        if (! array_key_exists($key, $arguments) || $arguments[$key] === null) {
            return null;
        }

        $value = $arguments[$key];

        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("'{$key}' must be a non-negative integer.");
        }

        return $value;
    }
}
