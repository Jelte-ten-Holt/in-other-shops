<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Exceptions;

/**
 * Thrown when a cart-able model is deleted while a live (non-expired) cart still
 * references it — deleting it would strand those cart lines against a missing
 * record. Order lines are unaffected (they snapshot), so this guards carts only.
 */
final class CartReferencesCartableException extends CommerceException
{
    public static function forCartable(string $label, int $liveCartCount): self
    {
        return new self(sprintf(
            'Cannot delete "%s": it is referenced by %d live cart%s. Remove it from those carts or let them expire first.',
            $label,
            $liveCartCount,
            $liveCartCount === 1 ? '' : 's',
        ));
    }
}
