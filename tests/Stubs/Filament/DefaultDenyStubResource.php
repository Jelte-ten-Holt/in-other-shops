<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs\Filament;

use InOtherShops\Support\Filament\PackageResource;
use InOtherShops\Tests\Stubs\TestStockable;

/**
 * A package Resource (extends {@see PackageResource}) used to prove the
 * default-deny authorization contract in {@see \InOtherShops\Tests\Feature\Support\PackageResourceAuthorizationTest}.
 */
final class DefaultDenyStubResource extends PackageResource
{
    protected static ?string $model = TestStockable::class;
}
