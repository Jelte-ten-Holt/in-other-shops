<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Actions;

use InOtherShops\Pricing\Actions\CreatePrice;
use InOtherShops\Pricing\Contracts\HasPrices;
use InOtherShops\Pricing\DTOs\PriceData;
use InOtherShops\Variants\Contracts\HasVariants;
use InOtherShops\Variants\Events\VariantCreated;
use InOtherShops\Variants\Exceptions\InvalidVariantOptionsException;
use InOtherShops\Variants\Models\Variant;
use InOtherShops\Variants\Variants;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates one variant for an owner from a set of option values, copying the
 * owner's current prices as a template. Pass no values for a default variant
 * (the flat-owner migration path).
 */
final class CreateVariant
{
    public function __construct(private readonly CreatePrice $createPrice) {}

    /**
     * @param  array<int>  $optionValueIds
     */
    public function __invoke(
        Model&HasVariants $owner,
        array $optionValueIds = [],
        ?string $sku = null,
        ?int $position = null,
    ): Variant {
        $values = $this->resolveValues($optionValueIds);
        $this->guardOneValuePerOption($values);
        $this->guardOptionsDeclaredOnOwner($owner, $values);

        $variant = DB::transaction(function () use ($owner, $values, $sku, $position): Variant {
            /** @var Variant $variant */
            $variant = $owner->variants()->create([
                'sku' => $sku,
                'position' => $position ?? 0,
            ]);

            if ($values->isNotEmpty()) {
                $variant->optionValues()->attach($values->modelKeys());
            }

            $this->copyOwnerPriceTemplate($owner, $variant);

            return $variant;
        });

        VariantCreated::dispatch($variant);

        return $variant;
    }

    /** @param array<int> $ids @return Collection<int, \InOtherShops\Variants\Models\OptionValue> */
    private function resolveValues(array $ids): Collection
    {
        $ids = array_values(array_unique($ids));

        $values = Variants::optionValue()::query()->with('option')->findMany($ids);

        if ($values->count() !== count($ids)) {
            throw new InvalidArgumentException('One or more option values do not exist.');
        }

        return $values;
    }

    private function guardOneValuePerOption(Collection $values): void
    {
        $duplicated = $values->groupBy('option_id')->first(fn (Collection $group): bool => $group->count() > 1);

        if ($duplicated !== null) {
            throw InvalidVariantOptionsException::multipleValuesForOption($duplicated->first()->option->slug);
        }
    }

    private function guardOptionsDeclaredOnOwner(Model&HasVariants $owner, Collection $values): void
    {
        if ($values->isEmpty()) {
            return;
        }

        $declared = $owner->options()->pluck('options.id');

        $undeclared = $values->first(fn (Model $value): bool => ! $declared->contains($value->option_id));

        if ($undeclared !== null) {
            throw InvalidVariantOptionsException::optionNotDeclared($undeclared->option->slug);
        }
    }

    private function copyOwnerPriceTemplate(Model $owner, Variant $variant): void
    {
        if (! $owner instanceof HasPrices) {
            return;
        }

        foreach ($owner->prices as $price) {
            ($this->createPrice)($variant, new PriceData(
                amount: $price->amount,
                currency: $price->currency,
                priceListId: $price->price_list_id,
                minimumQuantity: $price->minimum_quantity,
            ));
        }
    }
}
