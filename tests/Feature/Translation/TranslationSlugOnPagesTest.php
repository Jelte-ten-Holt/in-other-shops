<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Translation;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Tests\Stubs\Filament\StubEditableResource\Pages\CreateStubEditable;
use InOtherShops\Tests\Stubs\Filament\StubEditableResource\Pages\EditStubEditable;
use InOtherShops\Tests\Stubs\TestEditable;
use InOtherShops\Tests\Support\BootsFilament;
use InOtherShops\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * `TranslationSchema::fields(slugSource:, slugTarget:)` derives the slug from
 * the default-locale source field on CREATE only. On a saved record the slug
 * is a URL; a retitle must not move it.
 */
final class TranslationSlugOnPagesTest extends TestCase
{
    use BootsFilament;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(new GenericUser(['id' => 1]));
    }

    #[Test]
    public function the_create_page_derives_the_slug_from_the_source_field(): void
    {
        Livewire::test(CreateStubEditable::class)
            ->fillForm(['translations.en.name' => 'First Light'])
            ->assertFormSet(['slug' => 'first-light']);
    }

    #[Test]
    public function retitling_a_saved_record_leaves_its_slug_alone(): void
    {
        $record = TestEditable::create(['slug' => 'first-light']);
        $record->setTranslation('name', 'en', 'First Light');
        $record->save();

        Livewire::test(EditStubEditable::class, ['record' => $record->getKey()])
            ->fillForm(['translations.en.name' => 'Second Light'])
            ->assertFormSet(['slug' => 'first-light'])
            ->call('save')
            ->assertHasNoFormErrors();

        $record = $record->fresh();

        $this->assertSame('first-light', $record->slug);
        $this->assertSame('Second Light', $record->translated('name'));
    }

    #[Test]
    public function the_slug_field_itself_is_still_editable_on_a_saved_record(): void
    {
        $record = TestEditable::create(['slug' => 'first-light']);
        $record->setTranslation('name', 'en', 'First Light');
        $record->save();

        Livewire::test(EditStubEditable::class, ['record' => $record->getKey()])
            ->fillForm(['slug' => 'renamed-on-purpose'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('renamed-on-purpose', $record->fresh()->slug);
    }
}
