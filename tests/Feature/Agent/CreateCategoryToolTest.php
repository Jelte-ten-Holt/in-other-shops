<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Agent\Tools\CreateCategory;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class CreateCategoryToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        request()->attributes->set('agent.is_admin', true);
    }

    #[Test]
    public function it_creates_a_subcategory_under_the_named_parent(): void
    {
        $games = Category::factory()->create(['slug' => 'games']);

        $result = app(CreateCategory::class)([
            'name' => ['en' => 'One-pager'],
            'description' => ['en' => 'Games that fit on one page.'],
            'parent' => 'games',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('one-pager', $result['data']['slug']);
        $this->assertSame('games', $result['data']['parent']);
        $this->assertSame(['en' => 'Games that fit on one page.'], $result['data']['descriptions']);

        $created = Category::query()->where('slug', 'one-pager')->sole();
        $this->assertSame($games->id, $created->parent_id);
        $this->assertSame('One-pager', $created->translated('name', 'en'));
    }

    #[Test]
    public function without_a_parent_it_is_top_level(): void
    {
        $result = app(CreateCategory::class)(['name' => ['en' => 'Games']]);

        $this->assertNull($result['data']['parent']);
        $this->assertNull(Category::query()->where('slug', 'games')->value('parent_id'));
    }

    #[Test]
    public function an_unknown_parent_slug_is_refused_and_nothing_is_written(): void
    {
        try {
            app(CreateCategory::class)(['name' => ['en' => 'One-pager'], 'parent' => 'gamez']);
            $this->fail('An unknown parent must be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("'gamez'", $e->getMessage());
        }

        $this->assertSame(0, Category::query()->count());
    }

    #[Test]
    public function an_existing_slug_is_refused(): void
    {
        Category::factory()->create(['slug' => 'games']);

        $result = app(CreateCategory::class)(['name' => ['en' => 'Games']]);

        $this->assertSame('slug_taken', $result['error']['code']);
        $this->assertSame(1, Category::query()->count());
    }

    #[Test]
    public function it_refuses_a_caller_without_the_admin_scope_and_writes_nothing(): void
    {
        request()->attributes->set('agent.is_admin', false);

        $result = app(CreateCategory::class)(['name' => ['en' => 'Games']]);

        $this->assertSame('forbidden', $result['error']['code']);
        $this->assertSame(0, Category::query()->count());
    }

    #[Test]
    public function the_name_must_include_the_default_locale(): void
    {
        config(['translation.locales' => ['es', 'en'], 'translation.default' => 'es']);

        $this->expectException(InvalidArgumentException::class);

        app(CreateCategory::class)(['name' => ['en' => 'Games']]);
    }
}
