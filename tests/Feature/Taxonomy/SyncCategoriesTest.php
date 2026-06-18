<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Taxonomy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Taxonomy\Actions\AttachCategory;
use InOtherShops\Taxonomy\Actions\DetachCategory;
use InOtherShops\Taxonomy\Actions\SyncCategories;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Tests\Stubs\TestTaxonomized;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SyncCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private SyncCategories $sync;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sync = new SyncCategories(new AttachCategory, new DetachCategory);
    }

    #[Test]
    public function it_attaches_the_given_set_and_rolls_counts_up_the_ancestor_chain(): void
    {
        [$root, $mid, $leaf] = $this->tree();
        $model = TestTaxonomized::factory()->create();

        ($this->sync)($model, [$leaf->id]);

        // A bare ->sync() would leave every row at 0; through the actions the
        // count rolls up the leaf and each ancestor.
        $this->assertSubtreeCount($leaf, 1);
        $this->assertSubtreeCount($mid, 1);
        $this->assertSubtreeCount($root, 1);
    }

    #[Test]
    public function it_replaces_the_set_detaching_the_removed_and_attaching_the_added(): void
    {
        $a = Category::factory()->create();
        $b = Category::factory()->create();
        $model = TestTaxonomized::factory()->create();

        ($this->sync)($model, [$a->id]);
        $this->assertSubtreeCount($a, 1);
        $this->assertSubtreeCount($b, 0);

        ($this->sync)($model, [$b->id]);

        $this->assertSubtreeCount($a, 0);
        $this->assertSubtreeCount($b, 1);
        $this->assertSame([$b->id], $this->attachedIds($model));
    }

    #[Test]
    public function syncing_the_same_set_is_a_no_op_for_counts_and_the_pivot(): void
    {
        $a = Category::factory()->create();
        $model = TestTaxonomized::factory()->create();

        ($this->sync)($model, [$a->id]);
        ($this->sync)($model, [(string) $a->id]); // also proves string ids are coerced

        $this->assertSubtreeCount($a, 1);
        $this->assertSame([$a->id], $this->attachedIds($model));
    }

    #[Test]
    public function an_empty_set_clears_all_categories(): void
    {
        $a = Category::factory()->create();
        $model = TestTaxonomized::factory()->create();

        ($this->sync)($model, [$a->id]);
        ($this->sync)($model, []);

        $this->assertSubtreeCount($a, 0);
        $this->assertSame([], $this->attachedIds($model));
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

    /** @return list<int> */
    private function attachedIds(TestTaxonomized $model): array
    {
        return $model->categories()->pluck('categories.id')
            ->map(static fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    private function assertSubtreeCount(Category $category, int $expected): void
    {
        $actual = (int) (DB::table('category_morph_counts')
            ->where('category_id', $category->id)
            ->where('morph_alias', 'test_taxonomized')
            ->value('count') ?? 0);

        self::assertSame($expected, $actual, "Expected count {$expected} on category #{$category->id}; got {$actual}.");
    }
}
