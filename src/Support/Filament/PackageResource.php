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

    /**
     * Translation key prefix for this resource's own labels, e.g.
     * `shops-tax::taxrate`. The base reads `{prefix}.model`, `{prefix}.model_plural`
     * and `{prefix}.nav` from it. Return null to keep Filament's class-name
     * derivation (the pre-i18n behaviour).
     *
     * Without this, Filament derives every sidebar entry and model label from the
     * class name — always English — which is why an otherwise-Spanish panel still
     * read "Tax Rates" and "Crear tax rate".
     */
    protected static function labelKey(): ?string
    {
        return null;
    }

    public static function getModelLabel(): string
    {
        $key = static::labelKey();

        return $key === null ? parent::getModelLabel() : __($key.'.model');
    }

    public static function getPluralModelLabel(): string
    {
        $key = static::labelKey();

        // Never fall through to Filament's Str::plural() for a translated label —
        // it pluralizes by English rules and would mangle e.g. "tasa de impuesto".
        return $key === null ? parent::getPluralModelLabel() : __($key.'.model_plural');
    }

    public static function getNavigationLabel(): string
    {
        $key = static::labelKey();

        return $key === null ? parent::getNavigationLabel() : __($key.'.nav');
    }

    /**
     * Title Case is an English typographic convention. Filament applies
     * `Str::ucwords()` to model labels for headings, which turns a correct Spanish
     * label ("tasa de impuesto") into "Tasa De Impuesto". Keep the title-casing for
     * English — so English panels render byte-identically to before — and trust the
     * translated label's own casing in every other locale.
     */
    public static function hasTitleCaseModelLabel(): bool
    {
        return str_starts_with(app()->getLocale(), 'en');
    }
}
