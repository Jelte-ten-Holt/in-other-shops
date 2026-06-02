<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Exceptions;

/**
 * A variant's option-value set is invalid: it picks two values from the same
 * option, or references an option the owner hasn't declared as an axis.
 */
final class InvalidVariantOptionsException extends VariantsException
{
    public static function multipleValuesForOption(string $optionSlug): self
    {
        return new self("A variant may carry at most one value per option; option \"{$optionSlug}\" was given more than one.");
    }

    public static function optionNotDeclared(string $optionSlug): self
    {
        return new self("Option \"{$optionSlug}\" is not declared on this owner — attach it as an axis before creating variants that use its values.");
    }
}
