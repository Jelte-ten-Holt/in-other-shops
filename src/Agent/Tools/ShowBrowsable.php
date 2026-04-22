<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Tools;

use InOtherShops\Agent\AgentTool;
use InOtherShops\Storefront\Actions\ShowBrowsable as ShowBrowsableAction;
use InOtherShops\Storefront\Contracts\HasStorefrontPresence;
use InOtherShops\Storefront\Resources\BrowsableResource;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class ShowBrowsable extends AgentTool
{
    public function __construct(private readonly ShowBrowsableAction $showBrowsable) {}

    public static function identifier(): string
    {
        return 'show_browsable';
    }

    public static function displayName(): string
    {
        return 'Show browsable';
    }

    public function description(): string
    {
        return 'Fetch one storefront item (product, bundle, etc.) by type and slug.';
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
                'slug' => [
                    'type' => 'string',
                    'description' => 'Slug of the browsable to fetch.',
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
        $modelClass = $this->resolveModelClass($type);

        $model = ($this->showBrowsable)($modelClass, $slug);

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

        return [
            'ok' => true,
            'target' => $target,
            'data' => (new BrowsableResource($model))->toArray(Request::create('/', 'GET')),
        ];
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
}
