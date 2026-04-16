<?php

declare(strict_types=1);

namespace InOtherShops\Storefront\Resources;

use InOtherShops\Pricing\Contracts\HasPrices;
use InOtherShops\Storefront\Contracts\HasAvailability;
use InOtherShops\Storefront\DTOs\StorefrontContext;
use InOtherShops\Taxonomy\Contracts\HasCategories;
use InOtherShops\Taxonomy\Contracts\HasTags;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \InOtherShops\Storefront\Contracts\HasStorefrontPresence */
final class BrowsableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->baseData();

        $this->addPriceData($data);
        $this->addAvailabilityData($data);
        $this->addCategoryData($data);
        $this->addTagData($data);

        return $data;
    }

    private function baseData(): array
    {
        return [
            'type' => $this->resource->getAttribute('browsable_type') ?? $this->resolveBrowsableType(),
            'name' => $this->resource->getBrowsableName(),
            'slug' => $this->resource->getBrowsableSlug(),
            'description' => $this->resource->getBrowsableDescription(),
        ];
    }

    private function addPriceData(array &$data): void
    {
        if (! $this->resource instanceof HasPrices) {
            return;
        }

        $context = app(StorefrontContext::class);
        $price = $this->resource->priceFor($context->currency);

        $data['price'] = $price !== null ? new PriceResource($price) : null;
        $data['prices'] = PriceResource::collection($this->whenLoaded('prices'));
    }

    private function addAvailabilityData(array &$data): void
    {
        if (! $this->resource instanceof HasAvailability) {
            return;
        }

        $data['in_stock'] = $this->resource->isInStock();
    }

    private function addCategoryData(array &$data): void
    {
        if (! $this->resource instanceof HasCategories) {
            return;
        }

        $data['categories'] = CategoryResource::collection($this->whenLoaded('categories'));
    }

    private function addTagData(array &$data): void
    {
        if (! $this->resource instanceof HasTags) {
            return;
        }

        $data['tags'] = TagResource::collection($this->whenLoaded('tags'));
    }

    private function resolveBrowsableType(): ?string
    {
        /** @var array<string, class-string> $models */
        $models = config('storefront.models', []);

        $class = $this->resource::class;

        foreach ($models as $type => $modelClass) {
            if ($class === $modelClass) {
                return $type;
            }
        }

        return null;
    }
}
