<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Support\Filament;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Tests\Stubs\Filament\StubEditableResource\Pages\CreateStubEditable;
use InOtherShops\Tests\Stubs\Filament\StubEditableResource\Pages\EditStubEditable;
use InOtherShops\Tests\Stubs\TestEditable;
use InOtherShops\Tests\Support\BootsFilament;
use InOtherShops\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * Proves the fixture itself: a consumer-shaped Resource whose Create/Edit
 * pages carry the package's manual-sync Schemas can be mounted, filled and
 * saved under Livewire's test harness, and the schemas' writes land. The
 * behaviour tests that need this (two saves, media ids, slug on edit) are
 * beside it in this directory; this class only pins that the harness works.
 */
final class StubEditablePageTest extends TestCase
{
    use BootsFilament;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(new GenericUser(['id' => 1]));
    }

    #[Test]
    public function the_edit_page_mounts_and_hydrates_the_three_schemas(): void
    {
        $record = $this->editable('Original');
        app(AdjustStock::class)($record, 3, StockMovementReason::Adjusted, source: 'dashboard');

        Livewire::test(EditStubEditable::class, ['record' => $record->getKey()])
            ->assertOk()
            ->assertFormSet([
                'translations.en.name' => 'Original',
                'slug' => 'original',
                '_stock.stock_level' => 3,
                '_stock.adjustment_quantity' => null,
            ]);
    }

    #[Test]
    public function saving_the_edit_page_writes_through_every_schema(): void
    {
        $record = $this->editable('Original');

        Livewire::test(EditStubEditable::class, ['record' => $record->getKey()])
            ->fillForm([
                'translations.en.name' => 'Renamed',
                '_stock.adjustment_quantity' => 5,
                '_media.images' => [
                    ['type' => 'external', 'url' => 'https://example.test/cover.jpg', 'is_cover' => true],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $record = $record->fresh();

        $this->assertSame('Renamed', $record->translated('name'));
        $this->assertSame(5, $record->stockLevel());
        $this->assertSame(['https://example.test/cover.jpg'], $record->media()->pluck('url')->all());
    }

    #[Test]
    public function the_create_page_derives_the_slug_from_the_default_locale_name(): void
    {
        Livewire::test(CreateStubEditable::class)
            ->fillForm(['translations.en.name' => 'A Brand New Thing'])
            ->call('create')
            ->assertHasNoFormErrors();

        $record = TestEditable::query()->sole();

        $this->assertSame('a-brand-new-thing', $record->slug);
        $this->assertSame('A Brand New Thing', $record->translated('name'));
    }

    private function editable(string $name): TestEditable
    {
        $record = TestEditable::create(['slug' => str($name)->slug()->toString()]);
        $record->setTranslation('name', 'en', $name);
        $record->save();

        return $record;
    }
}
