<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Media;

use InOtherShops\Media\Models\Media;
use InOtherShops\Tests\Stubs\TestMediable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

final class InteractsWithMediaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cover_image_returns_the_row_marked_is_cover_across_collections(): void
    {
        $record = TestMediable::factory()->create();
        $imagesA = Media::factory()->create();
        $imagesB = Media::factory()->create();
        $document = Media::factory()->create();

        $record->media()->attach($imagesA->id, ['collection' => 'images', 'position' => 0, 'is_cover' => false]);
        $record->media()->attach($imagesB->id, ['collection' => 'images', 'position' => 1, 'is_cover' => false]);
        $record->media()->attach($document->id, ['collection' => 'documents', 'position' => 0, 'is_cover' => true]);

        $cover = $record->coverImage();

        $this->assertNotNull($cover);
        $this->assertSame($document->id, $cover->id);
    }

    #[Test]
    public function cover_image_falls_back_to_first_images_when_no_row_is_marked(): void
    {
        $record = TestMediable::factory()->create();
        $first = Media::factory()->create();
        $second = Media::factory()->create();

        $record->media()->attach($first->id, ['collection' => 'images', 'position' => 0, 'is_cover' => false]);
        $record->media()->attach($second->id, ['collection' => 'images', 'position' => 1, 'is_cover' => false]);

        $cover = $record->coverImage();

        $this->assertNotNull($cover);
        $this->assertSame($first->id, $cover->id);
    }

    #[Test]
    public function cover_image_returns_null_when_no_media_attached(): void
    {
        $record = TestMediable::factory()->create();

        $this->assertNull($record->coverImage());
    }

    #[Test]
    public function cover_image_falls_back_to_images_even_when_a_non_images_row_exists_unmarked(): void
    {
        $record = TestMediable::factory()->create();
        $document = Media::factory()->create();
        $image = Media::factory()->create();

        $record->media()->attach($document->id, ['collection' => 'documents', 'position' => 0, 'is_cover' => false]);
        $record->media()->attach($image->id, ['collection' => 'images', 'position' => 0, 'is_cover' => false]);

        $cover = $record->coverImage();

        $this->assertNotNull($cover);
        $this->assertSame($image->id, $cover->id);
    }

    #[Test]
    public function cover_image_resolves_from_a_loaded_media_relation_without_issuing_a_query(): void
    {
        // SCALE-4: every storefront caller eager-loads `media`; resolving the
        // cover must not query again — the whole point of `with('media')`.
        $record = TestMediable::factory()->create();
        $unmarked = Media::factory()->create();
        $cover = Media::factory()->create();

        $record->media()->attach($unmarked->id, ['collection' => 'images', 'position' => 0, 'is_cover' => false]);
        $record->media()->attach($cover->id, ['collection' => 'documents', 'position' => 0, 'is_cover' => true]);

        $loaded = TestMediable::query()->with('media')->findOrFail($record->id);

        DB::connection()->enableQueryLog();
        $resolved = $loaded->coverImage();
        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();

        $this->assertNotNull($resolved);
        $this->assertSame($cover->id, $resolved->id);
        $this->assertCount(0, $queries, 'A loaded media relation must resolve the cover in-memory with no extra query.');
    }

    #[Test]
    public function cover_image_fallback_to_first_images_also_uses_the_loaded_relation(): void
    {
        // The fallback path (no row flagged is_cover → first of `images`) goes
        // through firstMedia('images'), which must also skip the query when
        // the relation is loaded — and must not pick a non-images row.
        $record = TestMediable::factory()->create();
        $document = Media::factory()->create();
        $image = Media::factory()->create();

        $record->media()->attach($document->id, ['collection' => 'documents', 'position' => 0, 'is_cover' => false]);
        $record->media()->attach($image->id, ['collection' => 'images', 'position' => 1, 'is_cover' => false]);

        $loaded = TestMediable::query()->with('media')->findOrFail($record->id);

        DB::connection()->enableQueryLog();
        $resolved = $loaded->coverImage();
        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();

        $this->assertNotNull($resolved);
        $this->assertSame($image->id, $resolved->id);
        $this->assertCount(0, $queries, 'The images-collection fallback must also resolve in-memory when media is loaded.');
    }

    #[Test]
    public function the_loaded_relation_path_matches_the_query_path_result(): void
    {
        // Parity: loaded-path resolution must return the same rows the query
        // path does — cover flag across collections, position ordering, and
        // the firstMedia collection filter.
        $record = TestMediable::factory()->create();
        $imageLate = Media::factory()->create();
        $imageEarly = Media::factory()->create();
        $document = Media::factory()->create();

        // Attach out of position order to prove ordering comes from the pivot
        // position, not insertion order.
        $record->media()->attach($imageLate->id, ['collection' => 'images', 'position' => 5, 'is_cover' => false]);
        $record->media()->attach($imageEarly->id, ['collection' => 'images', 'position' => 1, 'is_cover' => false]);
        $record->media()->attach($document->id, ['collection' => 'documents', 'position' => 0, 'is_cover' => true]);

        $unloaded = TestMediable::query()->findOrFail($record->id);
        $loaded = TestMediable::query()->with('media')->findOrFail($record->id);

        $this->assertSame($unloaded->coverImage()?->id, $loaded->coverImage()?->id,
            'Cover resolution must match between the loaded and query paths.');
        $this->assertSame($document->id, $loaded->coverImage()?->id);

        $this->assertSame($unloaded->firstMedia()?->id, $loaded->firstMedia()?->id,
            'Unfiltered firstMedia must match between the loaded and query paths.');
        $this->assertSame($document->id, $loaded->firstMedia()?->id,
            'Unfiltered firstMedia returns the lowest pivot position across collections.');

        $this->assertSame($unloaded->firstMedia('images')?->id, $loaded->firstMedia('images')?->id,
            'Collection-filtered firstMedia must match between the loaded and query paths.');
        $this->assertSame($imageEarly->id, $loaded->firstMedia('images')?->id,
            'Collection-filtered firstMedia honors pivot position order, not insertion order.');
    }

    #[Test]
    public function the_loaded_relation_path_returns_null_when_nothing_matches(): void
    {
        $record = TestMediable::factory()->create();
        $document = Media::factory()->create();

        $record->media()->attach($document->id, ['collection' => 'documents', 'position' => 0, 'is_cover' => false]);

        $loaded = TestMediable::query()->with('media')->findOrFail($record->id);

        $this->assertNull($loaded->coverImage(), 'No cover flag and no images collection → null, same as the query path.');
        $this->assertNull($loaded->firstMedia('images'));
    }
}
