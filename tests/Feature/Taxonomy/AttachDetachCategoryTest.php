<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Taxonomy;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InOtherShops\Taxonomy\Actions\AttachCategory;
use InOtherShops\Taxonomy\Actions\DetachCategory;
use InOtherShops\Taxonomy\Events\CategoryAttached;
use InOtherShops\Taxonomy\Events\CategoryDetached;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Tests\Stubs\TestTaxonomized;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class AttachDetachCategoryTest extends TestCase
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
    public function it_writes_a_pivot_row_when_attaching_a_category(): void
    {
        $model = TestTaxonomized::factory()->create();
        $category = Category::factory()->create();

        ($this->attach)($model, $category);

        $this->assertSame(1, $model->categories()->count());
        $this->assertTrue($model->categories()->where('categories.id', $category->id)->exists());
    }

    #[Test]
    public function it_dispatches_category_attached_with_the_model_and_category(): void
    {
        Event::fake([CategoryAttached::class]);

        $model = TestTaxonomized::factory()->create();
        $category = Category::factory()->create();

        ($this->attach)($model, $category);

        Event::assertDispatched(
            CategoryAttached::class,
            fn (CategoryAttached $event) => $event->model->is($model)
                && $event->category->is($category),
        );
    }

    #[Test]
    public function attaching_the_same_category_twice_violates_the_unique_pivot_constraint(): void
    {
        // The categorizables migration declares (category_id, type, id) UNIQUE.
        // The action is not idempotent at the DB layer — second attach throws.
        // This test pins that contract; if the consumer wants idempotency they
        // must wrap with `syncWithoutDetaching` or similar at the call site.
        $model = TestTaxonomized::factory()->create();
        $category = Category::factory()->create();

        ($this->attach)($model, $category);

        $this->expectException(QueryException::class);

        ($this->attach)($model, $category);
    }

    #[Test]
    public function it_does_not_dispatch_category_attached_when_the_unique_constraint_fires(): void
    {
        // If the second attach throws on the unique constraint, the event must
        // NOT fire — otherwise consumers would log a phantom attach for a row
        // that was never written.
        Event::fake([CategoryAttached::class]);

        $model = TestTaxonomized::factory()->create();
        $category = Category::factory()->create();

        ($this->attach)($model, $category);

        try {
            ($this->attach)($model, $category);
        } catch (QueryException) {
            // expected
        }

        Event::assertDispatchedTimes(CategoryAttached::class, 1);
    }

    #[Test]
    public function it_only_attaches_to_the_target_model_not_to_siblings(): void
    {
        $target = TestTaxonomized::factory()->create();
        $other = TestTaxonomized::factory()->create();
        $category = Category::factory()->create();

        ($this->attach)($target, $category);

        $this->assertSame(1, $target->categories()->count());
        $this->assertSame(0, $other->categories()->count(),
            'Attach must scope to the target model only.');
    }

    #[Test]
    public function it_removes_the_pivot_row_when_detaching_a_category(): void
    {
        $model = TestTaxonomized::factory()->create();
        $category = Category::factory()->create();

        ($this->attach)($model, $category);
        ($this->detach)($model, $category);

        $this->assertSame(0, $model->categories()->count());
    }

    #[Test]
    public function it_dispatches_category_detached_with_the_model_and_category(): void
    {
        $model = TestTaxonomized::factory()->create();
        $category = Category::factory()->create();
        ($this->attach)($model, $category);

        Event::fake([CategoryDetached::class]);

        ($this->detach)($model, $category);

        Event::assertDispatched(
            CategoryDetached::class,
            fn (CategoryDetached $event) => $event->model->is($model)
                && $event->category->is($category),
        );
    }

    #[Test]
    public function detaching_a_category_that_was_never_attached_does_not_dispatch_an_event(): void
    {
        // detach() on a non-existent pivot row affects 0 rows. The action
        // gates the event on the affected-row count so listeners
        // maintaining derived state (e.g. MaintainCategoryCounts) do not
        // see a phantom detach for a row that was never there.
        Event::fake([CategoryDetached::class]);

        $model = TestTaxonomized::factory()->create();
        $category = Category::factory()->create();

        ($this->detach)($model, $category);

        $this->assertSame(0, $model->categories()->count());
        Event::assertNotDispatched(CategoryDetached::class);
    }
}
