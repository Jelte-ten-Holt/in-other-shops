<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use InOtherShops\Agent\Tools\ListCategories;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class ListCategoriesToolTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_root_categories_with_children(): void
    {
        $root = Category::factory()->create(['slug' => 'root-cat', 'position' => 0]);
        $root->setTranslations('en', ['name' => 'Root']);

        $child = Category::factory()->create(['slug' => 'child-cat', 'parent_id' => $root->id, 'position' => 0]);
        $child->setTranslations('en', ['name' => 'Child']);

        $result = app(ListCategories::class)([]);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('root-cat', $result['data'][0]['slug']);
        $this->assertSame('Root', $result['data'][0]['name']);
        $this->assertCount(1, $result['data'][0]['children']);
        $this->assertSame('child-cat', $result['data'][0]['children'][0]['slug']);
    }

    #[Test]
    public function it_excludes_inactive_categories_by_default(): void
    {
        $active = Category::factory()->create(['slug' => 'active-cat']);
        $active->setTranslations('en', ['name' => 'Active']);

        $inactive = Category::factory()->inactive()->create(['slug' => 'inactive-cat']);
        $inactive->setTranslations('en', ['name' => 'Inactive']);

        $result = app(ListCategories::class)([]);

        $slugs = array_column($result['data'], 'slug');
        $this->assertContains('active-cat', $slugs);
        $this->assertNotContains('inactive-cat', $slugs);
    }

    #[Test]
    public function include_inactive_returns_inactive_categories_too(): void
    {
        $active = Category::factory()->create(['slug' => 'active-cat']);
        $active->setTranslations('en', ['name' => 'Active']);

        $inactive = Category::factory()->inactive()->create(['slug' => 'inactive-cat']);
        $inactive->setTranslations('en', ['name' => 'Inactive']);

        $result = app(ListCategories::class)(['include_inactive' => true]);

        $slugs = array_column($result['data'], 'slug');
        $this->assertContains('active-cat', $slugs);
        $this->assertContains('inactive-cat', $slugs);
    }
}
