<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Minimal stand-in for a consuming project's User model in agent-tool
 * tests. Exposes `->customer` as a public property so duck-typed callers
 * (see `ResolveCallerCustomerId`) resolve the attached Customer without
 * a database relation.
 *
 * Not used outside the test suite.
 */
final class AgentTestUser
{
    public function __construct(public readonly ?Model $customer) {}
}
