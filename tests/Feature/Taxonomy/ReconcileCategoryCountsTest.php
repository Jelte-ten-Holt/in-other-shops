<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Taxonomy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Taxonomy\Actions\AttachCategory;
use InOtherShops\Taxonomy\Actions\ReconcileCategoryCounts;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Tests\Stubs\TestTaxonomized;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ReconcileCategoryCountsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_clean_when_counts_match_the_pivot(): void
    {
        [, , $leaf] = $this->tree();
        (new AttachCategory)(TestTaxonomized::factory()->create(), $leaf); // maintains counts

        $report = (new ReconcileCategoryCounts)();

        $this->assertTrue($report->isClean());
        $this->assertSame(0, $report->issueCount());
    }

    #[Test]
    public function it_detects_missing_rows_when_an_attach_bypassed_the_events(): void
    {
        // The FD1 bug, reproduced: a raw pivot attach (no CategoryAttached) leaves
        // category_morph_counts empty. The tripwire must catch it — across the
        // whole ancestor chain, since the rollup expects all three.
        [$root, $mid, $leaf] = $this->tree();
        TestTaxonomized::factory()->create()->categories()->attach($leaf->id); // raw — bypasses MaintainCategoryCounts

        $report = (new ReconcileCategoryCounts)();

        $this->assertFalse($report->isClean());

        $byCategory = collect($report->drift)->keyBy('category_id');
        foreach ([$root, $mid, $leaf] as $cat) {
            $this->assertSame(0, $byCategory[$cat->id]['stored']);
            $this->assertSame(1, $byCategory[$cat->id]['expected']);
        }
    }

    #[Test]
    public function it_detects_orphan_rows_with_no_pivot_basis(): void
    {
        $cat = Category::factory()->create();
        DB::table('category_morph_counts')->insert([
            'category_id' => $cat->id,
            'morph_alias' => 'ghost',
            'count' => 4,
        ]);

        $report = (new ReconcileCategoryCounts)();

        $this->assertFalse($report->isClean());
        $this->assertSame(1, $report->issueCount());
        $this->assertSame(
            ['category_id' => $cat->id, 'morph_alias' => 'ghost', 'stored' => 4, 'expected' => 0],
            $report->drift[0],
        );
    }

    #[Test]
    public function recompute_restores_a_clean_reconcile(): void
    {
        [, , $leaf] = $this->tree();
        TestTaxonomized::factory()->create()->categories()->attach($leaf->id); // raw bypass → drift

        $this->assertFalse((new ReconcileCategoryCounts)()->isClean());

        $this->artisan('taxonomy:recompute-category-counts')->assertExitCode(0);

        $this->assertTrue((new ReconcileCategoryCounts)()->isClean());
    }

    #[Test]
    public function the_command_exits_zero_when_clean_and_non_zero_on_drift(): void
    {
        [, , $leaf] = $this->tree();

        $this->artisan('taxonomy:reconcile-category-counts')->assertExitCode(0); // no attachments = clean

        TestTaxonomized::factory()->create()->categories()->attach($leaf->id); // raw bypass

        $this->artisan('taxonomy:reconcile-category-counts')->assertExitCode(1);
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
}
