<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs\Filament;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use InOtherShops\Inventory\Filament\InventorySchema;
use InOtherShops\Media\Filament\MediaSchema;
use InOtherShops\Tests\Stubs\Filament\StubEditableResource\Pages\CreateStubEditable;
use InOtherShops\Tests\Stubs\Filament\StubEditableResource\Pages\EditStubEditable;
use InOtherShops\Tests\Stubs\Filament\StubEditableResource\Pages\ListStubEditables;
use InOtherShops\Tests\Stubs\TestEditable;
use InOtherShops\Translation\Filament\TranslationSchema;

/**
 * A consumer-shaped Resource for driving the package's manual-sync Schemas
 * through real Filament pages.
 *
 * Consumers embed {@see TranslationSchema}, {@see MediaSchema} and
 * {@see InventorySchema} in their own Resources and wire the fill/save halves
 * on their own Create/Edit pages. Nothing in the package exercised that page
 * lifecycle until this fixture: the static `fillFormData`/`saveFormData` tests
 * cannot see what Livewire does to form state between two saves, which is
 * exactly where the one-shot stock adjustment and the media `media_id` bugs
 * lived (quality-fixes-brief §2, 2026-09-01).
 *
 * Deliberately a plain `Resource`, not {@see \InOtherShops\Support\Filament\PackageResource}:
 * the package base default-denies without a policy, and this fixture is about
 * form lifecycle, not authorization — {@see DefaultDenyStubResource} covers
 * that. Mounted on {@see TestPanelProvider}; boot it with
 * {@see \InOtherShops\Tests\Support\BootsFilament}.
 */
final class StubEditableResource extends Resource
{
    protected static ?string $model = TestEditable::class;

    protected static ?string $slug = 'stub-editables';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TranslationSchema::fields(
                ['name' => TextInput::make('name')->required()],
                slugSource: 'name',
                slugTarget: 'slug',
            ),
            TextInput::make('slug')->required(),
            MediaSchema::mediaRepeater('images'),
            InventorySchema::stockSection(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('slug'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStubEditables::route('/'),
            'create' => CreateStubEditable::route('/create'),
            'edit' => EditStubEditable::route('/{record}/edit'),
        ];
    }
}
