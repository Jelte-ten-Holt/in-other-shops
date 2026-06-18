<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\DTOs;

/**
 * The result of a read-only category-counts reconciliation pass. Each drift row
 * is a `(category, morph_alias)` whose stored `category_morph_counts.count` no
 * longer equals the pivot-derived expectation — covering all three failure
 * shapes at once:
 *
 *  - **missing** — `stored = 0, expected > 0` (the attach bypassed the events;
 *    the symptom that produced the empty category table-of-contents);
 *  - **orphan** — `stored > 0, expected = 0` (a stale row whose pivot basis is
 *    gone, e.g. a categorized model hard-deleted without detaching);
 *  - **wrong** — both non-zero but unequal (a partial/lost delta).
 *
 * The pivot is the source of truth; `category_morph_counts` is a maintained
 * rollup, so any divergence is a bug elsewhere — surfaced here rather than left
 * to quietly mis-state the nav/table-of-contents.
 */
final readonly class CategoryCountReport
{
    /**
     * @param  list<array{category_id: int, morph_alias: string, stored: int, expected: int}>  $drift
     */
    public function __construct(
        public array $drift,
    ) {}

    public function isClean(): bool
    {
        return $this->drift === [];
    }

    public function issueCount(): int
    {
        return count($this->drift);
    }
}
