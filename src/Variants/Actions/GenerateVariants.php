<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Actions;

use InOtherShops\Variants\Contracts\HasVariants;
use InOtherShops\Variants\Models\Variant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Generates the variants for an owner as the cartesian product of selected
 * option values, one variant per combination. Declares the owner's axes from
 * the given options first, and skips combinations that already exist so it can
 * be re-run after adding a value without duplicating variants.
 */
final class GenerateVariants
{
    public function __construct(private readonly CreateVariant $createVariant) {}

    /**
     * @param  array<int, array<int>>  $valueIdsByOption  option_id => [option_value_id, ...]
     * @return Collection<int, Variant>
     */
    public function __invoke(Model&HasVariants $owner, array $valueIdsByOption): Collection
    {
        $valueIdsByOption = array_filter($valueIdsByOption, fn (array $values): bool => $values !== []);

        $this->declareAxes($owner, array_keys($valueIdsByOption));

        $existing = $this->existingCombinationKeys($owner);

        $created = collect();

        foreach ($this->cartesianProduct(array_values($valueIdsByOption)) as $combination) {
            if ($existing->contains($this->combinationKey($combination))) {
                continue;
            }

            $created->push(($this->createVariant)($owner, $combination));
        }

        return $created;
    }

    /** @param array<int> $optionIds */
    private function declareAxes(Model&HasVariants $owner, array $optionIds): void
    {
        $position = 0;
        $pivot = [];

        foreach ($optionIds as $optionId) {
            $pivot[$optionId] = ['position' => $position++];
        }

        $owner->options()->syncWithoutDetaching($pivot);
    }

    /** @return Collection<int, string> */
    private function existingCombinationKeys(Model&HasVariants $owner): Collection
    {
        return $owner->variants()
            ->with('optionValues')
            ->get()
            ->map(fn (Variant $variant): string => $this->combinationKey($variant->optionValues->modelKeys()));
    }

    /**
     * @param  array<int, array<int>>  $valueGroups
     * @return array<int, array<int>>
     */
    private function cartesianProduct(array $valueGroups): array
    {
        $combinations = [[]];

        foreach ($valueGroups as $values) {
            $next = [];
            foreach ($combinations as $combination) {
                foreach ($values as $value) {
                    $next[] = [...$combination, $value];
                }
            }
            $combinations = $next;
        }

        return $combinations;
    }

    /** @param array<int> $valueIds */
    private function combinationKey(array $valueIds): string
    {
        sort($valueIds);

        return implode('-', $valueIds);
    }
}
