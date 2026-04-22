<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Tools;

use InOtherShops\Agent\AgentTool;
use InOtherShops\Storefront\Actions\ListBrowsables;
use InOtherShops\Storefront\Contracts\HasStorefrontPresence;
use InOtherShops\Storefront\Resources\BrowsableResource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class BrowseCatalog extends AgentTool
{
    public function __construct(private readonly ListBrowsables $listBrowsables) {}

    public static function identifier(): string
    {
        return 'browse_catalog';
    }

    public static function displayName(): string
    {
        return 'Browse catalog';
    }

    public function description(): string
    {
        return 'List storefront items (products, bundles, etc.) with pagination and optional category, tag, and search filters.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'description' => 'Browsable type key from config("storefront.models"). E.g. "product", "bundle".',
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Category slug to filter by.',
                ],
                'tag' => [
                    'type' => 'string',
                    'description' => 'Tag slug to filter by.',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Substring match against name and description.',
                ],
                'sort' => [
                    'type' => 'string',
                    'description' => 'Sort key. Allowed: name, created_at, published_at. Prefix with - for descending.',
                ],
                'page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Page number, 1-indexed.',
                ],
                'per_page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'description' => 'Items per page. Capped at 100.',
                ],
            ],
            'required' => ['type'],
            'additionalProperties' => false,
        ];
    }

    public function __invoke(array $arguments): array
    {
        $type = (string) ($arguments['type'] ?? '');
        $modelClass = $this->resolveModelClass($type);
        $request = $this->buildRequest($arguments);

        $paginator = ($this->listBrowsables)($modelClass, $request);

        return $this->shapePaginatedResponse($paginator, $request);
    }

    /** @return class-string<HasStorefrontPresence> */
    private function resolveModelClass(string $type): string
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

    /** @param array<string, mixed> $arguments */
    private function buildRequest(array $arguments): Request
    {
        return Request::create('/', 'GET', $arguments);
    }

    private function shapePaginatedResponse(LengthAwarePaginator $paginator, Request $request): array
    {
        $data = $paginator->getCollection()
            ->map(fn ($model) => (new BrowsableResource($model))->toArray($request))
            ->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
