<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Support\Filament;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Tests\Stubs\Filament\StubEditableResource\Pages\EditStubEditable;
use InOtherShops\Tests\Stubs\TestEditable;
use InOtherShops\Tests\Support\BootsFilament;
use InOtherShops\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * The "Listed" toggle through a mounted Edit page: what the editor switches off
 * is what the pivot stores, and the form shows it again after the save.
 */
final class MediaListedTogglePageTest extends TestCase
{
    use BootsFilament;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(new GenericUser(['id' => 1]));
        config(['media.collections.images.listed_toggle' => true]);
    }

    #[Test]
    public function an_editor_takes_a_row_off_the_list_and_the_form_keeps_it_off(): void
    {
        $record = TestEditable::create(['slug' => 'thing']);
        $record->setTranslation('name', 'en', 'Thing');
        $record->save();

        $page = Livewire::test(EditStubEditable::class, ['record' => $record->getKey()])
            ->fillForm([
                '_media.images' => [
                    ['type' => 'external', 'url' => 'https://example.test/listed.jpg', 'is_listed' => true],
                    ['type' => 'external', 'url' => 'https://example.test/unlisted.jpg', 'is_listed' => false],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            ['https://example.test/listed.jpg' => true, 'https://example.test/unlisted.jpg' => false],
            $record->fresh()->media()->get()->mapWithKeys(fn ($media): array => [$media->url => $media->pivot->is_listed])->all(),
        );

        $this->assertSame(
            [true, false],
            array_column(array_values($page->get('data._media.images')), 'is_listed'),
        );
    }
}
