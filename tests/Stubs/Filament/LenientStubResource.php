<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs\Filament;

use Filament\Resources\Resource;
use InOtherShops\Tests\Stubs\TestStockable;

/**
 * Control: a plain Filament Resource that keeps the framework's lenient default
 * (`$shouldCheckPolicyExistence = true`). It exists only to demonstrate — in the
 * same test — that stock Filament *allows* a policy-less model where
 * {@see DefaultDenyStubResource} *denies* it. If Filament ever flips its own
 * default, the contrast assertion turns this into a canary.
 */
final class LenientStubResource extends Resource
{
    protected static ?string $model = TestStockable::class;
}
