<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs\Filament\StubEditableResource\Pages;

use Filament\Resources\Pages\ListRecords;
use InOtherShops\Tests\Stubs\Filament\StubEditableResource;

/**
 * The index page exists so the Create/Edit pages have somewhere to redirect
 * and a breadcrumb root; nothing tests it directly.
 */
final class ListStubEditables extends ListRecords
{
    protected static string $resource = StubEditableResource::class;
}
