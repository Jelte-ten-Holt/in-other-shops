<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Taxonomy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Taxonomy\Actions\AttachCategory;
use InOtherShops\Taxonomy\Actions\ListCategoryAttachments;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Tests\Stubs\TestBrowsable;
use InOtherShops\Tests\Stubs\TestTaxonomized;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ListCategoryAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private ListCategoryAttachments $list;

    protected function setUp(): void
    {
        parent::setUp();

        $this->list = new ListCategoryAttachments;
    }

    #[Test]
    public function it_returns_an_empty_array_when_nothing_is_attached(): void
    {
        $category = Category::factory()->create();

        $this->assertSame([], ($this->list)($category));
    }

    #[Test]
    public function it_groups_attachments_by_morph_type_with_type_keys_sorted(): void
    {
        $category = Category::factory()->create();

        $this->attachTaxonomized($category, 'Beta');
        $this->attachTaxonomized($category, 'Alpha');
        $this->attachBrowsable($category, 'Zeta');

        $grouped = ($this->list)($category);

        $this->assertSame(['test_browsable', 'test_taxonomized'], array_keys($grouped));
    }

    #[Test]
    public function it_labels_items_by_name_and_sorts_them_alphabetically(): void
    {
        $category = Category::factory()->create();

        $this->attachTaxonomized($category, 'Beta');
        $this->attachTaxonomized($category, 'Alpha');

        $grouped = ($this->list)($category);

        $this->assertSame(['Alpha', 'Beta'], $grouped['test_taxonomized']);
    }

    #[Test]
    public function it_falls_back_to_a_type_and_id_label_for_unresolvable_types(): void
    {
        $category = Category::factory()->create();

        DB::table('categorizables')->insert([
            'category_id' => $category->id,
            'categorizable_type' => 'ghost',
            'categorizable_id' => 42,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $grouped = ($this->list)($category);

        $this->assertSame(['ghost' => ['Ghost #42']], $grouped);
    }

    private function attachTaxonomized(Category $category, string $name): void
    {
        $model = TestTaxonomized::factory()->create(['name' => $name]);

        (new AttachCategory)($model, $category);
    }

    private function attachBrowsable(Category $category, string $name): void
    {
        $model = TestBrowsable::factory()->create(['name' => $name]);

        DB::table('categorizables')->insert([
            'category_id' => $category->id,
            'categorizable_type' => 'test_browsable',
            'categorizable_id' => $model->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
