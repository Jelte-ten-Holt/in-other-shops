<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Tools;

use InOtherShops\Agent\AgentTool;
use InOtherShops\Agent\Support\ResolveBrowsableModel;
use InOtherShops\Storefront\Actions\ShowBrowsable as ShowBrowsableAction;
use InOtherShops\Storefront\Resources\BrowsableResource;
use Illuminate\Http\Request;

final class ShowBrowsable extends AgentTool
{
    public function __construct(
        private readonly ShowBrowsableAction $showBrowsable,
        private readonly ResolveBrowsableModel $resolveBrowsableModel,
    ) {}

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
                    'description' => 'Browsable type key from config("storefront.models"). Exact key as configured by the consuming project.',
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
        $modelClass = ($this->resolveBrowsableModel)($type);

        $model = ($this->showBrowsable)($modelClass, $slug);

        $target = ['type' => $type, 'slug' => $slug];

        if ($model === null) {
            return $this->failure('not_found', "No {$type} with slug '{$slug}'.", $target);
        }

        return [
            'ok' => true,
            'target' => $target,
            'data' => (new BrowsableResource($model))->toArray(Request::create('/', 'GET')),
        ];
    }

}
