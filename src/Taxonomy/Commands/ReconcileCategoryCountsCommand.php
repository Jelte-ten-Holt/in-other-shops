<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InOtherShops\Taxonomy\Actions\ReconcileCategoryCounts;

/**
 * Read-only category-counts tripwire. Reports — never repairs — drift between
 * `category_morph_counts` and the `categorizables` pivot rollup, and exits
 * non-zero when any is found so a scheduled run surfaces the problem to
 * monitoring instead of letting it rot (the empty category table-of-contents
 * was exactly this drift, undetected). Repair is the separate
 * `taxonomy:recompute-category-counts`. Not auto-scheduled: consumers wire it
 * into their own scheduler + alerting.
 */
final class ReconcileCategoryCountsCommand extends Command
{
    protected $signature = 'taxonomy:reconcile-category-counts';

    protected $description = 'Report category subtree-count drift vs the categorizables pivot (read-only; exits non-zero on drift)';

    public function handle(ReconcileCategoryCounts $reconcile): int
    {
        $report = $reconcile();

        if ($report->isClean()) {
            $this->info('Category counts reconciled clean: every category_morph_counts row matches the pivot rollup.');

            return self::SUCCESS;
        }

        $this->error($report->issueCount().' category-count row(s) diverge from the categorizables pivot (run taxonomy:recompute-category-counts to repair):');
        $this->table(
            ['category_id', 'morph_alias', 'stored', 'expected', 'delta'],
            array_map(static fn (array $d): array => [
                $d['category_id'],
                $d['morph_alias'],
                $d['stored'],
                $d['expected'],
                $d['stored'] - $d['expected'],
            ], $report->drift),
        );

        Log::warning('Category-count reconciliation found drift', ['rows' => $report->issueCount()]);

        return self::FAILURE;
    }
}
