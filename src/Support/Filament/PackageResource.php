<?php

declare(strict_types=1);

namespace InOtherShops\Support\Filament;

use Filament\Resources\Resource;

/**
 * Base for every Filament Resource this package ships.
 *
 * Security — default deny. A package Resource lands in each consumer's admin
 * panel, and Filament's stock authorization is *lenient*: when no policy (or no
 * matching policy method) exists for the model, {@see get_authorization_response}
 * returns `Response::allow()`. For resources that ship inside a distributed
 * package that is a privilege-escalation hole — a consumer that simply forgets
 * to write a policy for `OrderResource` exposes every order to any panel user.
 *
 * Setting `$shouldCheckPolicyExistence = false` flips that: every ability is
 * resolved through the Gate directly, so a missing policy method resolves to
 * *deny* instead of *allow*. A `Gate::before` blanket grant (the consumer's
 * super-admin bypass) is still honoured, because the Gate runs its
 * before-callbacks first. If a consumer additionally enables Filament's global
 * strict-authorization mode, a missing policy throws a `LogicException` rather
 * than silently denying — both are fail-closed.
 *
 * The consuming app is therefore required to ship a policy that *grants* access
 * for each package model it wants reachable (see the policy-mapping list in
 * docs/periphery.md). No policy => no access.
 */
abstract class PackageResource extends Resource
{
    protected static bool $shouldCheckPolicyExistence = false;
}
