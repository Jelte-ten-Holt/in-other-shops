<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Media;

use InOtherShops\Media\Models\Media;
use InOtherShops\Tests\Stubs\TestMediable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
