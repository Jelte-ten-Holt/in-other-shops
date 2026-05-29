<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Taxonomy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InOtherShops\Taxonomy\Actions\AttachCategory;
use InOtherShops\Taxonomy\Actions\DetachCategory;
use InOtherShops\Taxonomy\Events\CategoryAttached;
use InOtherShops\Taxonomy\Events\CategoryDeleted;
use InOtherShops\Taxonomy\Events\CategoryMoved;
use InOtherShops\Taxonomy\Exceptions\CategoryHasChildrenException;
use InOtherShops\Taxonomy\Exceptions\MorphAliasTooLongException;
use InOtherShops\Taxonomy\Listeners\MaintainCategoryCounts;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Tests\Stubs\TestTaxonomized;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class MaintainCategoryCountsTest extends TestCase
{
    use RefreshDatabase;

    private AttachCategory $attach;

    private DetachCategory $detach;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attach = new AttachCategory;
        $this->detach = new DetachCategory;
    }

    #[Test]
    public function attach_increments_count_on_the_category_and_every_ancestor(): void
    {
        [$root, $mid, $leaf] = $this->tree();
        $model = TestTaxonomized::factory()->create();

        ($this->attach)($model, $leaf);

        $this->assertSubtreeCount('test_taxonomized', $leaf, 1);
        $this->assertSubtreeCount('test_taxonomized', $mid, 1);
        $this->assertSubtreeCount('test_taxonomized', $root, 1);
    }

    #[Test]
    public function attach_does_not_increment_unrelated_branches(): void
    {
        [$root, $mid, $leaf] = $this->tree();
        $sibling = Category::factory()->create(['parent_id' => $mid->id]);
        $otherRoot = Category::factory()->create();

        $model = TestTaxonomized::factory()->create();

        ($this->attach)($model, $leaf);

        $this->assertSubtreeCount('test_taxonomized', $sibling, 0);
        $this->assertSubtreeCount('test_taxonomized', $otherRoot, 0);
    }

    #[Test]
    public function detach_decrements_count_on_the_category_and_every_ancestor(): void
    {
        [$root, $mid, $leaf] = $this->tree();
        $model = TestTaxonomized::factory()->create();

        ($this->attach)($model, $leaf);
        ($this->detach)($model, $leaf);

        $this->assertSubtreeCount('test_taxonomized', $leaf, 0);
        $this->assertSubtreeCount('test_taxonomized', $mid, 0);
        $this->assertSubtreeCount('test_taxonomized', $root, 0);
    }

    #[Test]
    public function detach_of_an_unattached_pair_does_not_touch_counts(): void
    {
        // Companion to the no-event guarantee in AttachDetachCategoryTest:
        // because the action skips dispatch when nothing was removed, the
        // counts table is never decremented by a phantom detach.
        [$root, $mid, $leaf] = $this->tree();
        $a = TestTaxonomized::factory()->create();
        $b = TestTaxonomized::factory()->create();

        ($this->attach)($a, $leaf);
        ($this->detach)($b, $leaf);

        $this->assertSubtreeCount('test_taxonomized', $leaf, 1);
        $this->assertSubtreeCount('test_taxonomized', $mid, 1);
        $this->assertSubtreeCount('test_taxonomized', $root, 1);
    }

    #[Test]
    public function moving_a_subtree_shifts_its_counts_from_old_to_new_chain(): void
    {
        [$rootA, $midA, $leafA] = $this->tree();
        $rootB = Category::factory()->create();
        $midB = Category::factory()->create(['parent_id' => $rootB->id]);

        $model = TestTaxonomized::factory()->create();
        ($this->attach)($model, $leafA);

        $this->assertSubtreeCount('test_taxonomized', $rootA, 1);
        $this->assertSubtreeCount('test_taxonomized', $rootB, 0);

        $midA->update(['parent_id' => $midB->id]);

        $this->assertSubtreeCount('test_taxonomized', $leafA, 1);
        $this->assertSubtreeCount('test_taxonomized', $midA, 1);
        $this->assertSubtreeCount('test_taxonomized', $midB, 1);
        $this->assertSubtreeCount('test_taxonomized', $rootB, 1);
        $this->assertSubtreeCount('test_taxonomized', $rootA, 0);
    }

    #[Test]
    public function move_to_root_only_decrements_the_old_chain(): void
    {
        [$rootA, $midA, $leafA] = $this->tree();

        $model = TestTaxonomized::factory()->create();
        ($this->attach)($model, $leafA);

        $midA->update(['parent_id' => null]);

        $this->assertSubtreeCount('test_taxonomized', $leafA, 1);
        $this->assertSubtreeCount('test_taxonomized', $midA, 1);
        $this->assertSubtreeCount('test_taxonomized', $rootA, 0);
    }

    #[Test]
    public function move_from_root_only_increments_the_new_chain(): void
    {
        $orphan = Category::factory()->create();
        $newRoot = Category::factory()->create();

        $model = TestTaxonomized::factory()->create();
        ($this->attach)($model, $orphan);

        $orphan->update(['parent_id' => $newRoot->id]);

        $this->assertSubtreeCount('test_taxonomized', $orphan, 1);
        $this->assertSubtreeCount('test_taxonomized', $newRoot, 1);
    }

    #[Test]
    public function deleting_a_category_decrements_old_ancestors_by_its_total(): void
    {
        [$root, $mid, $leaf] = $this->tree();

        $a = TestTaxonomized::factory()->create();
        $b = TestTaxonomized::factory()->create();
        ($this->attach)($a, $leaf);
        ($this->attach)($b, $leaf);

        $leaf->delete();

        $this->assertSubtreeCount('test_taxonomized', $mid, 0);
        $this->assertSubtreeCount('test_taxonomized', $root, 0);
        $this->assertSame(0, DB::table('category_morph_counts')->where('category_id', $leaf->id)->count(),
            'Deleted category counts row must cascade away.');
    }

    #[Test]
    public function deleting_a_leaf_propagates_its_total_up_the_chain(): void
    {
        [$root, $mid, $leaf] = $this->tree();

        $model = TestTaxonomized::factory()->create();
        ($this->attach)($model, $leaf);

        $leaf->delete();

        $this->assertSubtreeCount('test_taxonomized', $mid, 0);
        $this->assertSubtreeCount('test_taxonomized', $root, 0);
    }

    #[Test]
    public function deleting_a_category_with_children_throws_and_leaves_state_untouched(): void
    {
        // restrictOnDelete on parent_id is the DB-level backstop; the
        // observer enforces the same rule earlier so we can throw a typed
        // exception and so the listener never sees a phantom delete.
        [$root, $mid, $leaf] = $this->tree();

        $model = TestTaxonomized::factory()->create();
        ($this->attach)($model, $leaf);

        Event::fake([CategoryDeleted::class]);

        try {
            $mid->delete();
            $this->fail('Expected CategoryHasChildrenException.');
        } catch (CategoryHasChildrenException) {
            // expected
        }

        $this->assertTrue(Category::query()->whereKey($mid->getKey())->exists(),
            'A refused delete must leave the row in place.');
        $this->assertSubtreeCount('test_taxonomized', $root, 1,
            'Counts must not be decremented when the delete is refused.');
        $this->assertSubtreeCount('test_taxonomized', $mid, 1);
        $this->assertSubtreeCount('test_taxonomized', $leaf, 1);
        Event::assertNotDispatched(CategoryDeleted::class);
    }

    #[Test]
    public function a_parent_loop_does_not_send_the_listener_into_an_infinite_walk(): void
    {
        // Cycles aren't enforced at the DB level; the package's job is to
        // not infinite-loop if one ever appears. This test creates the
        // cycle by raw update (bypassing the observer) so the move event
        // doesn't fire — then exercises attach, which walks ancestors.
        $a = Category::factory()->create();
        $b = Category::factory()->create(['parent_id' => $a->id]);

        DB::table('categories')->where('id', $a->id)->update(['parent_id' => $b->id]);

        $model = TestTaxonomized::factory()->create();
        ($this->attach)($model, $b);

        // The walk must terminate; both nodes get exactly one increment.
        $this->assertSubtreeCount('test_taxonomized', $a, 1);
        $this->assertSubtreeCount('test_taxonomized', $b, 1);
    }

    #[Test]
    public function recompute_command_rebuilds_counts_after_drift(): void
    {
        [$root, $mid, $leaf] = $this->tree();
        $model = TestTaxonomized::factory()->create();
        ($this->attach)($model, $leaf);

        DB::table('category_morph_counts')->delete();

        $this->artisan('taxonomy:recompute-category-counts')->assertExitCode(0);

        $this->assertSubtreeCount('test_taxonomized', $leaf, 1);
        $this->assertSubtreeCount('test_taxonomized', $mid, 1);
        $this->assertSubtreeCount('test_taxonomized', $root, 1);
    }

    #[Test]
    public function recompute_command_overwrites_inflated_counts_and_clears_orphan_rows(): void
    {
        // B-4 / T-7: drift is not always "rows are missing" — counts can be
        // inflated, and orphan (alias, category) rows can linger for items that
        // no longer exist. The rebuild must reset to the pivot truth, not add
        // on top of the drifted values. (The concurrent-writer guarantee — the
        // advisory lock and upsert final write — needs multiple connections and
        // can't be exercised on SQLite's single writer; it's covered by code
        // review against MySQL/Postgres.)
        [$root, $mid, $leaf] = $this->tree();
        $model = TestTaxonomized::factory()->create();
        ($this->attach)($model, $leaf);

        DB::table('category_morph_counts')
            ->where('category_id', $root->id)
            ->update(['count' => 99]);
        DB::table('category_morph_counts')->insert([
            'category_id' => $mid->id,
            'morph_alias' => 'ghost_alias',
            'count' => 7,
        ]);

        $this->artisan('taxonomy:recompute-category-counts')->assertExitCode(0);

        $this->assertSubtreeCount('test_taxonomized', $root, 1);
        $this->assertSubtreeCount('test_taxonomized', $mid, 1);
        $this->assertSubtreeCount('test_taxonomized', $leaf, 1);
        $this->assertSame(
            0,
            DB::table('category_morph_counts')->where('morph_alias', 'ghost_alias')->count(),
            'Orphan drift rows must be cleared by the rebuild.',
        );
    }

    #[Test]
    public function recompute_command_is_idempotent(): void
    {
        [$root, $mid, $leaf] = $this->tree();
        $model = TestTaxonomized::factory()->create();
        ($this->attach)($model, $leaf);

        $this->artisan('taxonomy:recompute-category-counts')->assertExitCode(0);
        $this->artisan('taxonomy:recompute-category-counts')->assertExitCode(0);

        $this->assertSubtreeCount('test_taxonomized', $leaf, 1);
        $this->assertSubtreeCount('test_taxonomized', $mid, 1);
        $this->assertSubtreeCount('test_taxonomized', $root, 1);
    }

    #[Test]
    public function recompute_command_exits_cleanly_with_an_empty_pivot(): void
    {
        Category::factory()->create();

        $this->artisan('taxonomy:recompute-category-counts')->assertExitCode(0);

        $this->assertSame(0, DB::table('category_morph_counts')->count());
    }

    #[Test]
    public function attach_rejects_a_morph_alias_longer_than_the_counts_column(): void
    {
        // B-3: an over-length morph alias — typically an unregistered FQCN —
        // would truncate silently on MySQL and diverge the counts table from
        // the pivot forever. The listener rejects it before writing anything.
        // SQLite ignores varchar length, so the column can't catch this; the
        // application-level guard must.
        [$root, $mid, $leaf] = $this->tree();

        $model = new class extends Model
        {
            public function getMorphClass(): string
            {
                return str_repeat('A', 256);
            }

            public function getKey(): int
            {
                return 1;
            }
        };

        try {
            (new MaintainCategoryCounts)->onAttached(new CategoryAttached($model, $leaf));
            $this->fail('Expected MorphAliasTooLongException.');
        } catch (MorphAliasTooLongException) {
            // expected
        }

        $this->assertSame(0, DB::table('category_morph_counts')->count(),
            'No count rows may be written when the alias is rejected.');
    }

    #[Test]
    public function a_listener_failure_during_a_move_rolls_back_the_reparent_and_the_counts(): void
    {
        // B-2 (observer path): Category::save() wraps the parent_id UPDATE and
        // the CategoryMoved count-shift in one transaction. A downstream
        // CategoryMoved listener throwing must roll BOTH back — leaving the node
        // where it was with counts intact, not reparented with counts shifted.
        [$root, $mid, $leaf] = $this->tree();
        $otherRoot = Category::factory()->create();
        $model = TestTaxonomized::factory()->create();
        ($this->attach)($model, $leaf);

        \Illuminate\Support\Facades\Event::listen(CategoryMoved::class, function (): void {
            throw new \RuntimeException('downstream move listener blew up');
        });

        $leaf->parent_id = $otherRoot->id;

        try {
            $leaf->save();
            $this->fail('Expected the listener exception to propagate.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($mid->id, $leaf->fresh()->parent_id,
            'The reparent must roll back when the move reaction throws.');
        $this->assertSubtreeCount('test_taxonomized', $root, 1);
        $this->assertSubtreeCount('test_taxonomized', $mid, 1);
        $this->assertSubtreeCount('test_taxonomized', $leaf, 1);
        $this->assertSubtreeCount('test_taxonomized', $otherRoot, 0);
    }

    #[Test]
    public function a_listener_failure_during_a_delete_rolls_back_the_delete_and_the_counts(): void
    {
        // B-2 (observer path): Category::delete() wraps the row delete and the
        // CategoryDeleted ancestor decrement in one transaction. A downstream
        // CategoryDeleted listener throwing must leave the row present and the
        // counts untouched.
        [$root, $mid, $leaf] = $this->tree();
        $model = TestTaxonomized::factory()->create();
        ($this->attach)($model, $leaf);

        \Illuminate\Support\Facades\Event::listen(CategoryDeleted::class, function (): void {
            throw new \RuntimeException('downstream delete listener blew up');
        });

        try {
            $leaf->delete();
            $this->fail('Expected the listener exception to propagate.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertTrue(Category::query()->whereKey($leaf->id)->exists(),
            'The category row must survive a rolled-back delete.');
        $this->assertSubtreeCount('test_taxonomized', $root, 1);
        $this->assertSubtreeCount('test_taxonomized', $mid, 1);
        $this->assertSubtreeCount('test_taxonomized', $leaf, 1);
    }

    /**
     * @return array{0: Category, 1: Category, 2: Category} root, mid, leaf
     */
    private function tree(): array
    {
        $root = Category::factory()->create();
        $mid = Category::factory()->create(['parent_id' => $root->id]);
        $leaf = Category::factory()->create(['parent_id' => $mid->id]);

        return [$root, $mid, $leaf];
    }

    private function assertSubtreeCount(string $alias, Category $category, int $expected): void
    {
        $actual = (int) (DB::table('category_morph_counts')
            ->where('category_id', $category->id)
            ->where('morph_alias', $alias)
            ->value('count') ?? 0);

        self::assertSame(
            $expected,
            $actual,
            "Expected count {$expected} for alias '{$alias}' on category #{$category->id} ({$category->slug}); got {$actual}.",
        );
    }
}
