<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Exceptions;

final class MorphAliasTooLongException extends TaxonomyException
{
    public static function for(string $alias, int $maxLength): self
    {
        $length = strlen($alias);

        return new self(
            "Morph alias '{$alias}' is {$length} characters, exceeding the {$maxLength}-character "
            ."limit of category_morph_counts.morph_alias. This almost always means the model's "
            ."morph class is an unregistered fully-qualified class name. Register a short alias via "
            ."Relation::morphMap() (e.g. 'product' => Product::class) so the pivot and the counts "
            ."table store the same value. See src/Taxonomy/README.md.",
        );
    }
}
