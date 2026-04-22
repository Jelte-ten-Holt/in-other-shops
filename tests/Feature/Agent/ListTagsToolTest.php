<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use InOtherShops\Agent\Tools\ListTags;
use InOtherShops\Taxonomy\Models\Tag;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class ListTagsToolTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_all_active_tags_by_default(): void
    {
        $featured = Tag::factory()->create(['slug' => 'featured-tag', 'type' => 'featured']);
        $featured->setTranslations('en', ['name' => 'Featured']);

        $hidden = Tag::factory()->hidden()->create(['slug' => 'hidden-tag']);
        $hidden->setTranslations('en', ['name' => 'Hidden']);

        $result = app(ListTags::class)([]);

        $this->assertTrue($result['ok']);
        $slugs = array_column($result['data'], 'slug');
        $this->assertContains('featured-tag', $slugs);
        $this->assertContains('hidden-tag', $slugs);
    }

    #[Test]
    public function it_filters_by_tag_type(): void
    {
        $featured = Tag::factory()->create(['slug' => 'featured-tag', 'type' => 'featured']);
        $featured->setTranslations('en', ['name' => 'Featured']);

        $hidden = Tag::factory()->hidden()->create(['slug' => 'hidden-tag']);
        $hidden->setTranslations('en', ['name' => 'Hidden']);

        $result = app(ListTags::class)(['tag_type' => 'featured']);

        $this->assertCount(1, $result['data']);
        $this->assertSame('featured-tag', $result['data'][0]['slug']);
        $this->assertSame('featured', $result['data'][0]['type']);
    }

    #[Test]
    public function it_excludes_inactive_tags_unless_requested(): void
    {
        $active = Tag::factory()->create(['slug' => 'active-tag']);
        $active->setTranslations('en', ['name' => 'Active']);

        $inactive = Tag::factory()->inactive()->create(['slug' => 'inactive-tag']);
        $inactive->setTranslations('en', ['name' => 'Inactive']);

        $this->assertCount(1, app(ListTags::class)([])['data']);
        $this->assertCount(2, app(ListTags::class)(['include_inactive' => true])['data']);
    }
}
