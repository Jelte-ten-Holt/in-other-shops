<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Taxonomy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Taxonomy\Actions\AttachCategory;
use InOtherShops\Taxonomy\Actions\ListCategoryTree;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Tests\Stubs\TestTaxonomized;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ListCategoryTreeTest extends TestCase
{
    use RefreshDatabase;

    private ListCategoryTree $list;

    private AttachCategory $attach;

    protected function setUp(): void
    {
        parent::setUp();

        $this->list = new ListCategoryTree;
        $this->attach = new AttachCategory;
    }

    #[Test]
    public function it_returns_an_empty_collection_when_no_aliases_are_passed(): void
    {
        $this->assertSame(0, ($this->list)([])->count());
    }

    #[Test]
    public function it_returns_an_empty_collection_when_nothing_is_attached(): void
    {
        Category::factory()->create();

        $this->assertSame(0, ($this->list)(['test_taxonomized'])->count());
    }

    #[Test]
    public function it_returns_a_subtree_of_categories_that_have_anything_attached(): void
    {
        $root = Category::factory()->create(['slug' => 'root']);
        $mid = Category::factory()->create(['slug' => 'mid', 'parent_id' => $root->id]);
        $leaf = Category::factory()->create(['slug' => 'leaf', 'parent_id' => $mid->id]);

        $model = TestTaxonomized::factory()->create();
        ($this->attach)($model, $leaf);

        $tree = ($this->list)(['test_taxonomized']);

        $this->assertSame(1, $tree->count(), 'Only the root should be at the top level.');
        $this->assertSame('root', $tree->first()->slug);
        $this->assertSame('mid', $tree->first()->children->first()->slug);
        $this->assertSame('leaf', $tree->first()->children->first()->children->first()->slug);
    }

    #[Test]
    public function intermediate_ancestors_appear_even_without_direct_attachments(): void
    {
        // This is the original snag the user raised: "If 'roleplaying' has
        // no direct attachments but 'cyberpunk' (its child) does, will the
        // tree still surface 'roleplaying'?"
        $roleplaying = Category::factory()->create(['slug' => 'roleplaying']);
        $cyberpunk = Category::factory()->create(['slug' => 'cyberpunk', 'parent_id' => $roleplaying->id]);

        $model = TestTaxonomized::factory()->create();
        ($this->attach)($model, $cyberpunk);

        $tree = ($this->list)(['test_taxonomized']);

        $this->assertSame('roleplaying', $tree->first()->slug);
        $this->assertSame(1, (int) $tree->first()->relevant_count);
    }

    #[Test]
    public function categories_with_zero_count_for_the_queried_aliases_are_excluded(): void
    {
        $shown = Category::factory()->create(['slug' => 'shown']);
        $hidden = Category::factory()->create(['slug' => 'hidden']);

        $model = TestTaxonomized::factory()->create();
        ($this->attach)($model, $shown);

        $tree = ($this->list)(['test_taxonomized']);

        $this->assertSame(1, $tree->count());
        $this->assertSame('shown', $tree->first()->slug);
    }

    #[Test]
    public function inactive_categories_are_excluded_and_their_descendants_promote_to_root(): void
    {
        $deactivated = Category::factory()->create(['slug' => 'deactivated', 'is_active' => false]);
        $child = Category::factory()->create(['slug' => 'child', 'parent_id' => $deactivated->id]);

        $model = TestTaxonomized::factory()->create();
        ($this->attach)($model, $child);

        $tree = ($this->list)(['test_taxonomized']);

        $this->assertSame(1, $tree->count(),
            'The deactivated ancestor is filtered out; the child surfaces as a root of the visible tree.');
        $this->assertSame('child', $tree->first()->slug);
    }

    #[Test]
    public function relevant_count_sums_across_multiple_aliases(): void
    {
        // Seed the counts table directly so we can exercise multi-alias
        // summation without needing a second registered morph alias.
        $category = Category::factory()->create();
        DB::table('category_morph_counts')->insert([
            ['category_id' => $category->id, 'morph_alias' => 'product', 'count' => 4],
            ['category_id' => $category->id, 'morph_alias' => 'bundle', 'count' => 3],
            ['category_id' => $category->id, 'morph_alias' => 'content', 'count' => 9],
        ]);

        $tree = ($this->list)(['product', 'bundle']);

        $this->assertSame(1, $tree->count());
        $this->assertSame(7, (int) $tree->first()->relevant_count,
            'Multi-alias query should sum only the requested aliases, not unrelated ones.');
    }

    #[Test]
    public function roots_are_ordered_by_position(): void
    {
        $second = Category::factory()->create(['slug' => 'second', 'position' => 20]);
        $first = Category::factory()->create(['slug' => 'first', 'position' => 10]);

        $a = TestTaxonomized::factory()->create();
        $b = TestTaxonomized::factory()->create();
        ($this->attach)($a, $first);
        ($this->attach)($b, $second);

        $tree = ($this->list)(['test_taxonomized']);

        $this->assertSame(['first', 'second'], $tree->pluck('slug')->all());
    }

    #[Test]
    public function children_are_ordered_by_position(): void
    {
        $root = Category::factory()->create(['slug' => 'root']);
        $b = Category::factory()->create(['slug' => 'b', 'parent_id' => $root->id, 'position' => 20]);
        $a = Category::factory()->create(['slug' => 'a', 'parent_id' => $root->id, 'position' => 10]);

        $m1 = TestTaxonomized::factory()->create();
        $m2 = TestTaxonomized::factory()->create();
        ($this->attach)($m1, $a);
        ($this->attach)($m2, $b);

        $tree = ($this->list)(['test_taxonomized']);

        $this->assertSame(['a', 'b'], $tree->first()->children->pluck('slug')->all());
    }
}
