<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Taxonomy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Media\Contracts\HasMedia;
use InOtherShops\Media\Models\Media;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CategoryMediaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function category_satisfies_the_has_media_contract(): void
    {
        $this->assertInstanceOf(HasMedia::class, Category::factory()->create());
    }

    #[Test]
    public function it_stores_attached_media_under_the_category_morph_alias(): void
    {
        $category = Category::factory()->create();
        $image = Media::factory()->create();

        $category->media()->attach($image->id, ['collection' => 'images', 'position' => 0, 'is_cover' => false]);

        // Confirms the morph map ('category' => Category) keeps mediable_type a stable
        // short key rather than the FQCN.
        $this->assertDatabaseHas('mediables', [
            'media_id' => $image->id,
            'mediable_type' => 'category',
            'mediable_id' => $category->id,
            'collection' => 'images',
        ]);
    }

    #[Test]
    public function it_returns_its_cover_image(): void
    {
        $category = Category::factory()->create();
        $cover = Media::factory()->create();
        $other = Media::factory()->create();

        $category->media()->attach($other->id, ['collection' => 'images', 'position' => 0, 'is_cover' => false]);
        $category->media()->attach($cover->id, ['collection' => 'images', 'position' => 1, 'is_cover' => true]);

        $this->assertSame($cover->id, $category->coverImage()?->id);
    }

    #[Test]
    public function it_has_no_cover_image_when_no_media_is_attached(): void
    {
        $this->assertNull(Category::factory()->create()->coverImage());
    }
}
