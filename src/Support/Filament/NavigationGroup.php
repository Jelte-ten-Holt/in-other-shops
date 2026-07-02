<?php

declare(strict_types=1);

namespace InOtherShops\Support\Filament;

/**
 * The admin navigation groups this package's Filament Resources render under —
 * one group per domain (there is no shared "Shop" catch-all). Filament derives
 * the group label from the case name, so each case name is the label shown.
 *
 * Consumers extending a package Resource inherit its group; a consumer adding
 * its own Resources picks its own groups independently.
 */
enum NavigationGroup
{
    case Commerce;
    case Pricing;
    case Purchasing;
    case Tax;
    case Taxonomy;
    case Variants;
}
