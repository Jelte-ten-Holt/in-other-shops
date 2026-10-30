<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Agent\Tools\UpdateCategory;
use InOtherShops\Taxonomy\Actions\AttachCategory;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Tests\Stubs\TestTaxonomized;
use InOtherShops\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class UpdateCategoryToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        request()->attributes->set('agent.is_admin', true);
    }

    #[Test]
    public function moving_a_category_carries_its_item_counts_to_the_new_parent(): void
    {
        $games = Category::factory()->create(['slug' => 'games']);
        $lore = Category::factory()->create(['slug' => 'lore']);
        $onePager = Category::factory()->create(['slug' => 'one-pager', 'parent_id' => $lore->id]);
        (new AttachCategory)(TestTaxonomized::factory()->create(), $onePager);

        $result = app(UpdateCategory::class)(['slug' => 'one-pager', 'parent' => 'games']);

        $this->assertTrue($result['ok']);
        $this->assertSame('games', $result['data']['parent']);
        $this->assertSame($games->id, $onePager->fresh()->parent_id);
        $this->assertSame(1, $this->itemCount($games));
        $this->assertSame(0, $this->itemCount($lore));
    }

    #[Test]
    public function a_null_parent_makes_it_top_level(): void
    {
        $games = Category::factory()->create(['slug' => 'games']);
        $onePager = Category::factory()->create(['slug' => 'one-pager', 'parent_id' => $games->id]);

        app(UpdateCategory::class)(['slug' => 'one-pager', 'parent' => null]);

        $this->assertNull($onePager->fresh()->parent_id);
    }

    #[Test]
    public function a_move_under_its_own_subcategory_is_refused_and_nothing_changes(): void
    {
        $games = Category::factory()->create(['slug' => 'games']);
        Category::factory()->create(['slug' => 'one-pager', 'parent_id' => $games->id]);
        $games->setTranslation('name', 'en', 'Games');

        $result = app(UpdateCategory::class)([
            'slug' => 'games',
            'parent' => 'one-pager',
            'name' => ['en' => 'Renamed in the same call'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('category_cycle', $result['error']['code']);
        $this->assertNull($games->fresh()->parent_id);
        $this->assertSame('Games', $games->fresh()->translated('name', 'en'), 'The refused call must not half-apply.');
    }

    #[Test]
    public function an_unknown_parent_slug_is_refused(): void
    {
        Category::factory()->create(['slug' => 'games']);

        $this->expectException(InvalidArgumentException::class);

        app(UpdateCategory::class)(['slug' => 'games', 'parent' => 'nowhere']);
    }

    #[Test]
    public function names_and_descriptions_change_per_locale_and_the_rest_is_left_alone(): void
    {
        config(['translation.locales' => ['en', 'es']]);
        $games = Category::factory()->create(['slug' => 'games', 'position' => 4, 'is_active' => true]);
        $games->setTranslation('name', 'en', 'Games');
        $games->setTranslation('name', 'es', 'Juegos');

        $result = app(UpdateCategory::class)([
            'slug' => 'games',
            'name' => ['en' => 'Our games'],
            'description' => ['es' => 'Juegos que hemos publicado.'],
        ]);

        $this->assertSame(['en' => 'Our games', 'es' => 'Juegos'], $result['data']['names']);
        $this->assertSame(['es' => 'Juegos que hemos publicado.'], $result['data']['descriptions']);
        $this->assertSame(4, $games->fresh()->position);
        $this->assertTrue($games->fresh()->is_active);
    }

    #[Test]
    public function an_unknown_slug_is_not_found(): void
    {
        $result = app(UpdateCategory::class)(['slug' => 'nope', 'position' => 1]);

        $this->assertSame('not_found', $result['error']['code']);
    }

    #[Test]
    public function it_refuses_a_caller_without_the_admin_scope_and_changes_nothing(): void
    {
        $games = Category::factory()->create(['slug' => 'games']);
        request()->attributes->set('agent.is_admin', false);

        $result = app(UpdateCategory::class)(['slug' => 'games', 'is_active' => false]);

        $this->assertSame('forbidden', $result['error']['code']);
        $this->assertTrue($games->fresh()->is_active);
    }

    private function itemCount(Category $category): int
    {
        return (int) (DB::table('category_morph_counts')
            ->where('category_id', $category->id)
            ->where('morph_alias', 'test_taxonomized')
            ->value('count') ?? 0);
    }
}
