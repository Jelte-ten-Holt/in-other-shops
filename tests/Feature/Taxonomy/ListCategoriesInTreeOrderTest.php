<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Taxonomy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Taxonomy\Actions\ListCategoriesInTreeOrder;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ListCategoriesInTreeOrderTest extends TestCase
{
    use RefreshDatabase;

    private ListCategoriesInTreeOrder $list;

    protected function setUp(): void
    {
        parent::setUp();

        $this->list = new ListCategoriesInTreeOrder;
    }

    #[Test]
    public function it_returns_an_empty_list_when_there_are_no_categories(): void
    {
        $this->assertSame([], ($this->list)());
    }

    #[Test]
    public function it_returns_each_parent_immediately_followed_by_its_descendants(): void
    {
        $first = Category::factory()->create(['slug' => 'first', 'position' => 0]);
        $second = Category::factory()->create(['slug' => 'second', 'position' => 1]);

        $childA = Category::factory()->create(['slug' => 'child-a', 'parent_id' => $first->id, 'position' => 0]);
        $childB = Category::factory()->create(['slug' => 'child-b', 'parent_id' => $first->id, 'position' => 1]);
        $grandchild = Category::factory()->create(['slug' => 'grandchild', 'parent_id' => $childA->id, 'position' => 0]);

        $this->assertSame(
            [$first->id, $childA->id, $grandchild->id, $childB->id, $second->id],
            ($this->list)(),
        );
    }

    #[Test]
    public function it_orders_siblings_by_position_then_id(): void
    {
        $root = Category::factory()->create(['slug' => 'root', 'position' => 0]);

        $later = Category::factory()->create(['slug' => 'later', 'parent_id' => $root->id, 'position' => 5]);
        $earlier = Category::factory()->create(['slug' => 'earlier', 'parent_id' => $root->id, 'position' => 1]);

        $this->assertSame([$root->id, $earlier->id, $later->id], ($this->list)());
    }
}
