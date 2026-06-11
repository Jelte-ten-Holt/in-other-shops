<?php

declare(strict_types=1);

namespace InOtherShops\Support;

/**
 * Contract for status enums that drive a local state machine: a display
 * label/color plus an explicit transition table. Pair with
 * {@see StateTransitions} for the canTransitionTo() guard.
 *
 * PaymentStatus deliberately does NOT implement this: payment state is
 * driven by gateway webhook mapping, not a locally-enforced transition
 * table — adding allowedTransitions() there would invent a state machine
 * the gateway doesn't honor. (Package-tightening brief, WI-9.)
 */
interface Transitionable
{
    public function label(): string;

    public function color(): string;

    /** @return list<self> */
    public function allowedTransitions(): array;
}
