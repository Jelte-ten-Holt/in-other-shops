<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolve the Customer id attached to the currently-authenticated agent
 * caller. Duck-types `$user->customer` — works for any Eloquent model
 * that implements `InOtherShops\Commerce\Customer\Contracts\HasCustomer`,
 * and for test doubles that expose the same attribute shape.
 *
 * Returns null when there is no caller, no customer attached, or the
 * resolved value is not a Model.
 */
final class ResolveCallerCustomerId
{
    public function __invoke(?object $user): int|string|null
    {
        if ($user === null) {
            return null;
        }

        $customer = $user->customer ?? null;

        return $customer instanceof Model ? $customer->getKey() : null;
    }
}
