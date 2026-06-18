<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Actions;

use Illuminate\Support\Facades\DB;
use InOtherShops\Taxonomy\DTOs\CategoryCountReport;
use InOtherShops\Taxonomy\Support\CategoryCountAggregator;

/**
 * Read-only reconciliation of `category_morph_counts` against the pivot rollup
 * it is supposed to mirror. Writes nothing — it only reports drift so a
 * scheduled run can act as a tripwire (the standing defence against the bypass
 * class that left the live category table-of-contents empty: any attach path
 * that skips CategoryAttached/CategoryDetached leaves the counts stale and
 * nothing errors).
 *
 * Compares the live table to {@see CategoryCountAggregator::expected()} — the
 * same truth function the recompute command writes — so the detector and the
 * repair can never disagree.
 */
final class ReconcileCategoryCounts
{
    public function __invoke(): CategoryCountReport
    {
        $expected = CategoryCountAggregator::expected();
        $stored = $this->storedCounts();

        $drift = [];

        // Every expected (category, alias): a stored value that differs (absent → 0).
        foreach ($expected as $categoryId => $byAlias) {
            foreach ($byAlias as $alias => $expectedCount) {
                $storedCount = $stored[$categoryId][$alias] ?? 0;

                if ($storedCount !== $expectedCount) {
                    $drift[] = $this->row($categoryId, $alias, $storedCount, $expectedCount);
                }
            }
        }

        // Stored rows with no expectation (expected = 0) — orphans the first pass
        // can't see because it only walks the expected set.
        foreach ($stored as $categoryId => $byAlias) {
            foreach ($byAlias as $alias => $storedCount) {
                if (($expected[$categoryId][$alias] ?? 0) === 0) {
                    $drift[] = $this->row($categoryId, $alias, $storedCount, 0);
                }
            }
        }

        usort($drift, static fn (array $a, array $b): int => [$a['category_id'], $a['morph_alias']] <=> [$b['category_id'], $b['morph_alias']]);

        return new CategoryCountReport($drift);
    }

    /**
     * @return array<int, array<string, int>>  categoryId => [morph_alias => count]
     */
    private function storedCounts(): array
    {
        $stored = [];

        foreach (DB::table('category_morph_counts')->get(['category_id', 'morph_alias', 'count']) as $row) {
            $stored[(int) $row->category_id][(string) $row->morph_alias] = (int) $row->count;
        }

        return $stored;
    }

    /**
     * @return array{category_id: int, morph_alias: string, stored: int, expected: int}
     */
    private function row(int $categoryId, string $alias, int $stored, int $expected): array
    {
        return [
            'category_id' => $categoryId,
            'morph_alias' => $alias,
            'stored' => $stored,
            'expected' => $expected,
        ];
    }
}
