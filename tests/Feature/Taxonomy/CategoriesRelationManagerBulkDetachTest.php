<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Taxonomy;

use Closure;
use Filament\Actions\DetachBulkAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use InOtherShops\Taxonomy\Actions\AttachCategory;
use InOtherShops\Taxonomy\Events\CategoryDetached;
use InOtherShops\Taxonomy\Filament\RelationManagers\CategoriesRelationManager;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Tests\Stubs\TestTaxonomized;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionObject;

/**
 * Regression cover for the bulk-detach event gap (audit B-1): Filament's stock
 * `DetachBulkAction` removes pivot rows but fires no domain event, so
 * `MaintainCategoryCounts` never decrements `category_morph_counts` and the
 * filter UI keeps reporting detached categories as populated. The single-row
 * `DetachAction` dispatches `CategoryDetached`; the bulk variant must too.
 *
 * There is no concrete Filament Resource/Page in the package to host the
 * RelationManager via Livewire (it attaches to consumer models), so — like
 * `PaymentsRelationManagerRefundActionTest` — this reaches into the action's
 * registered `after` hook directly rather than booting a full table.
 */
final class CategoriesRelationManagerBulkDetachTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_bulk_detach_action_has_an_after_hook(): void
    {
        $action = $this->bulkDetachActionFor(TestTaxonomized::factory()->create());

        $this->assertInstanceOf(DetachBulkAction::class, $action);
        $this->assertInstanceOf(
            Closure::class,
            $this->afterHook($action),
            'DetachBulkAction must register an after() hook so detaches dispatch CategoryDetached.',
        );
    }

    #[Test]
    public function bulk_detach_dispatches_category_detached_for_every_record(): void
    {
        $owner = TestTaxonomized::factory()->create();
        $a = Category::factory()->create();
        $b = Category::factory()->create();

        Event::fake([CategoryDetached::class]);

        $this->runBulkDetachAfter($owner, collect([$a, $b]));

        Event::assertDispatchedTimes(CategoryDetached::class, 2);
        foreach ([$a, $b] as $category) {
            Event::assertDispatched(
                CategoryDetached::class,
                fn (CategoryDetached $event) => $event->model->is($owner)
                    && $event->category->is($category),
            );
        }
    }

    #[Test]
    public function bulk_detach_decrements_counts_on_every_ancestor(): void
    {
        // End-to-end through the real MaintainCategoryCounts listener: the bug
        // was that bulk detach left these counts untouched.
        $root = Category::factory()->create();
        $mid = Category::factory()->create(['parent_id' => $root->id]);
        $leaf = Category::factory()->create(['parent_id' => $mid->id]);

        $owner = TestTaxonomized::factory()->create();
        (new AttachCategory)($owner, $leaf);

        $this->assertSubtreeCount($leaf, 1);
        $this->assertSubtreeCount($mid, 1);
        $this->assertSubtreeCount($root, 1);

        $this->runBulkDetachAfter($owner, collect([$leaf]));

        $this->assertSubtreeCount($leaf, 0);
        $this->assertSubtreeCount($mid, 0);
        $this->assertSubtreeCount($root, 0);
    }

    private function runBulkDetachAfter(TestTaxonomized $owner, $records): void
    {
        $action = $this->bulkDetachActionFor($owner);
        $hook = $this->afterHook($action);

        $hook($records);
    }

    private function bulkDetachActionFor(TestTaxonomized $owner): DetachBulkAction
    {
        $manager = new CategoriesRelationManager;
        $manager->ownerRecord = $owner;

        $method = new ReflectionMethod(CategoriesRelationManager::class, 'detachBulkAction');
        $method->setAccessible(true);

        return $method->invoke($manager);
    }

    private function afterHook(DetachBulkAction $action): ?Closure
    {
        $property = (new ReflectionObject($action))->getProperty('after');
        $property->setAccessible(true);

        return $property->getValue($action);
    }

    private function assertSubtreeCount(Category $category, int $expected): void
    {
        $actual = (int) (DB::table('category_morph_counts')
            ->where('category_id', $category->id)
            ->where('morph_alias', 'test_taxonomized')
            ->value('count') ?? 0);

        self::assertSame($expected, $actual, "count for category #{$category->id}");
    }
}
