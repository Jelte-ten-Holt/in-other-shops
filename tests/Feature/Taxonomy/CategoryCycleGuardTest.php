<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Taxonomy;

use Filament\Forms\Components\Select;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use InOtherShops\Taxonomy\Exceptions\CategoryCycleException;
use InOtherShops\Taxonomy\Filament\Resources\CategoryResource\Pages\EditCategory;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Tests\Support\BootsFilament;
use InOtherShops\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * A category may not sit under itself or one of its own descendants. Consumers
 * walk the tree recursively (in-other-worlds' category page totals a subtree by
 * recursing into children), so a loop takes those pages down. The model refuses
 * the move; the admin keeps the impossible parents off the menu.
 */
final class CategoryCycleGuardTest extends TestCase
{
    use BootsFilament;
    use RefreshDatabase;

    #[Test]
    public function moving_a_category_under_its_own_grandchild_is_refused_and_nothing_is_written(): void
    {
        [$root, $child, $grandchild] = $this->chain();

        try {
            $root->update(['parent_id' => $grandchild->id]);
            $this->fail('A move that closes a loop must throw.');
        } catch (CategoryCycleException) {
        }

        $this->assertNull($root->fresh()->parent_id);
        $this->assertSame($root->id, $child->fresh()->parent_id);
    }

    #[Test]
    public function a_category_cannot_be_its_own_parent(): void
    {
        $category = Category::factory()->create();

        $this->expectException(CategoryCycleException::class);

        $category->update(['parent_id' => $category->id]);
    }

    #[Test]
    public function moving_a_category_under_an_unrelated_branch_is_allowed(): void
    {
        [$root, $child] = $this->chain();
        $other = Category::factory()->create();

        $child->update(['parent_id' => $other->id]);

        $this->assertSame($other->id, $child->fresh()->parent_id);
    }

    #[Test]
    public function moving_a_category_to_the_root_is_allowed(): void
    {
        [, $child] = $this->chain();

        $child->update(['parent_id' => null]);

        $this->assertNull($child->fresh()->parent_id);
    }

    #[Test]
    public function the_admin_parent_menu_leaves_out_the_category_and_its_descendants(): void
    {
        Gate::before(fn (): bool => true);
        $this->actingAs(new GenericUser(['id' => 1]));

        [$root, $child, $grandchild] = $this->chain();
        $other = Category::factory()->create();

        Livewire::test(EditCategory::class, ['record' => $child->getKey()])
            ->assertFormFieldExists('parent_id', function (Select $field) use ($root, $child, $grandchild, $other): bool {
                $offered = array_keys($field->getOptions());

                $this->assertContains($root->id, $offered);
                $this->assertContains($other->id, $offered);
                $this->assertNotContains($child->id, $offered, 'A category is not its own parent.');
                $this->assertNotContains($grandchild->id, $offered, 'A descendant cannot become the parent.');

                return true;
            });
    }

    /** @return array{Category, Category, Category} */
    private function chain(): array
    {
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $grandchild = Category::factory()->create(['parent_id' => $child->id]);

        return [$root, $child, $grandchild];
    }
}
