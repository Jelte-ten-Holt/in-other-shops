<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Support\Filament;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InOtherShops\Tests\Stubs\Filament\StubEditableResource\Pages\EditStubEditable;
use InOtherShops\Tests\Stubs\TestEditable;
use InOtherShops\Tests\Support\BootsFilament;
use InOtherShops\Tests\TestCase;
use Livewire\Livewire;
use Livewire\Features\SupportTesting\Testable;
use PHPUnit\Framework\Attributes\Test;

/**
 * The two consumer bugs that `refillManualFormState()` exists for, driven
 * through a real page: both were green on the static schema tests, because
 * both live in what Livewire keeps between two saves.
 */
final class ManualFormStateRefillTest extends TestCase
{
    use BootsFilament;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(new GenericUser(['id' => 1]));
        config(['media.disk' => 'public']);
        Storage::fake('public');
    }

    #[Test]
    public function a_second_save_does_not_reapply_the_one_shot_stock_adjustment(): void
    {
        $record = $this->editable('Adjusted');

        $page = $this->edit($record)
            ->fillForm(['_stock.adjustment_quantity' => 5])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(5, $record->fresh()->stockLevel());

        $page->assertFormSet(['_stock.adjustment_quantity' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(5, $record->fresh()->stockLevel(), 'the adjustment was applied again on the second save');
    }

    #[Test]
    public function a_second_save_keeps_an_uploaded_media_row_and_its_file(): void
    {
        UploadedFile::fake()->create('hero.jpeg', 100, 'image/jpeg')->storeAs('media', 'hero.jpeg', 'public');
        $record = $this->editable('Pictured');

        $page = $this->edit($record)
            // FileUpload keeps its raw state as [uuid => path]; a real page load
            // hydrates a bare string into that shape, the test harness's fill
            // does not, so hand it the stored shape directly.
            ->fillForm(['_media.images' => [['type' => 'upload', 'path' => [(string) Str::uuid() => 'media/hero.jpeg']]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $firstId = $record->media()->sole()->getKey();

        // Repeater items are re-keyed by uuid on hydrate, so read by position.
        $items = array_values($page->get('data._media.images'));
        $this->assertSame($firstId, $items[0]['media_id'] ?? null, 'the refill did not restore media_id');

        $page->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$firstId], $record->media()->pluck('media.id')->all(), 'the media row was deleted and re-created');
        $this->assertTrue(Storage::disk('public')->exists('media/hero.jpeg'), 'the uploaded file was deleted');
    }

    private function edit(TestEditable $record): Testable
    {
        return Livewire::test(EditStubEditable::class, ['record' => $record->getKey()]);
    }

    private function editable(string $name): TestEditable
    {
        $record = TestEditable::create(['slug' => str($name)->slug()->toString()]);
        $record->setTranslation('name', 'en', $name);
        $record->save();

        return $record;
    }
}
