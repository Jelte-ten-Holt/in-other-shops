<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Media;

use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Filament\RelationManagers\MediaRelationManager;
use InOtherShops\Media\Models\Media;
use InOtherShops\Tests\Stubs\Filament\StubEditableResource\Pages\EditStubEditable;
use InOtherShops\Tests\Stubs\TestEditable;
use InOtherShops\Tests\Support\BootsFilament;
use InOtherShops\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * The second admin upload surface. Its Edit action writes the form straight to
 * the row, so `path` always landed here — but `enrichFormData` only runs on
 * Create, so `filename`/`mime_type`/`size` went on describing the file that had
 * just been replaced, and Filament never removes a replaced file.
 *
 * There is no relation-manager-specific fix: this proves the model-level
 * invariant reaches a surface that knows nothing about it.
 */
final class MediaRelationManagerReplaceTest extends TestCase
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
    public function replacing_the_file_through_the_relation_manager_refreshes_metadata_and_drops_the_old_file(): void
    {
        // Real bytes: `UploadedFile::fake()->create()` writes an empty file and
        // only *reports* a size, which would make every size assertion 0 === 0.
        Storage::disk('public')->put('media/old.jpeg', str_repeat("\0", 1200));
        Storage::disk('public')->put('media/new.png', str_repeat("\0", 3400));

        $record = $this->editable();
        $media = Media::create([
            'type' => MediaType::Upload,
            'disk' => 'public',
            'path' => 'media/old.jpeg',
            'filename' => 'old.jpeg',
            'mime_type' => 'image/jpeg',
            'size' => 1200,
        ]);
        $record->media()->attach($media->getKey(), ['collection' => 'images', 'position' => 0]);

        $page = Livewire::test(MediaRelationManager::class, [
            'ownerRecord' => $record,
            'pageClass' => EditStubEditable::class,
        ])->mountAction(TestAction::make('edit')->table($media));

        // FileUpload's raw state is [livewire-id => path], and swapping the file
        // in the browser replaces that whole map rather than adding a key — a
        // `fillForm(['path' => [...]])` would merge into the mounted state and
        // the single-file cast would go on reading the *old* key.
        $page->set('mountedActions.0.data.path', ['replacement-id' => 'media/new.png'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $media->refresh();

        $this->assertSame('media/new.png', $media->path);
        $this->assertSame('new.png', $media->filename);
        $this->assertSame('image/png', $media->mime_type);
        $this->assertSame(3400, $media->size);
        $this->assertTrue(Storage::disk('public')->exists('media/new.png'));
        $this->assertFalse(
            Storage::disk('public')->exists('media/old.jpeg'),
            'the replaced file was left behind on disk',
        );
    }

    private function editable(): TestEditable
    {
        $record = TestEditable::create(['slug' => 'pictured']);
        $record->setTranslation('name', 'en', 'Pictured');
        $record->save();

        return $record;
    }
}
