<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Taxonomy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Taxonomy\Contracts\HasTags;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Taxonomy\Models\Tag;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CategoryTagsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function category_satisfies_the_has_tags_contract(): void
    {
        $this->assertInstanceOf(HasTags::class, Category::factory()->create());
    }

    #[Test]
    public function it_can_be_tagged_under_the_category_morph_alias(): void
    {
        $category = Category::factory()->create();
        $tag = Tag::factory()->create();

        $category->tags()->attach($tag->id);

        // The 'featured'-style flag relies on this morph alias staying a stable
        // short key so consumer queries (taggable_type = 'category') resolve.
        $this->assertDatabaseHas('taggables', [
            'tag_id' => $tag->id,
            'taggable_type' => 'category',
            'taggable_id' => $category->id,
        ]);
        $this->assertTrue($category->tags->contains($tag));
    }
}
