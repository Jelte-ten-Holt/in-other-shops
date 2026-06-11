<?php

declare(strict_types=1);

namespace InOtherShops\Support;

/**
 * The shared transition guard for {@see Transitionable} status enums.
 * `self` resolves to the consuming enum, so the signature stays
 * type-safe per enum without contravariance games in the interface.
 */
trait StateTransitions
{
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
