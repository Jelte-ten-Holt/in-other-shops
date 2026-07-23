<?php

declare(strict_types=1);

namespace InOtherShops\Support\Filament;

use Filament\Support\Contracts\HasLabel;

/**
 * The admin navigation groups this package's Filament Resources render under —
 * one group per domain (there is no shared "Shop" catch-all).
 *
 * Implements Filament's HasLabel so the sidebar heading is translated instead of
 * being the raw case name: Filament calls `$case->getLabel()` when the enum
 * implements this contract and falls back to the case name otherwise. Labels
 * live in `shops-common::nav.*`, whose `en` values are byte-identical to the
 * case names — so an English panel renders exactly as it did before.
 *
 * Consumers extending a package Resource inherit its group; a consumer adding
 * its own Resources picks its own groups independently.
 */
enum NavigationGroup implements HasLabel
{
    case Commerce;
    case Pricing;
    case Purchasing;
    case Tax;
    case Taxonomy;
    case Variants;

    public function getLabel(): string
    {
        return __('shops-common::nav.'.strtolower($this->name));
    }
}
