<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Storefront;

use InOtherShops\Storefront\Actions\ListBrowsables;
use InOtherShops\Tests\Stubs\TestBrowsable;
use InOtherShops\Tests\Stubs\TestTranslatableBrowsable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;

/**
 * Search and sort must work for both catalog shapes the package supports.
 *
 * `name` and `description` are contract METHODS, not promised columns: one
 * consumer stores them as columns, another as rows in `translations`. The
 * original implementation issued `where('name', 'like', …)` and
 * `orderBy('name')` unconditionally, which is correct for the first shape and
 * a hard SQL error ("Unknown column 'name'") for the second — a 500 on the
 * listing the moment a shopper typed in the search box.
 *
 * Every case below therefore runs against both stubs.
 *
 * ⚠ This suite runs on SQLite, which does NOT reproduce the production symptom.
 * Laravel quotes identifiers with double quotes, and SQLite's long-standing
 * misfeature degrades a double-quoted token that matches no column into a
 * STRING LITERAL. So `where('name', 'like', …)` against a table with no `name`
 * column silently becomes `WHERE 'name' LIKE …` — always false, never an error
 * — and `orderBy('name')` becomes a no-op sort on a constant. MySQL raises
 * "Unknown column 'name' in 'field list'" and returns a 500.
 *
 * Consequence: assertions here must be written so a silently-wrong query gives
 * a WRONG ANSWER rather than a coincidentally-right one. Two cases below needed
 * reworking for exactly this reason — they passed against the broken code until
 * their fixtures were arranged to discriminate.
 */
final class ListBrowsablesSearchAndSortTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function searches_a_column_backed_catalog_by_name(): void
    {
        TestBrowsable::factory()->create(['name' => 'Agate Choker', 'slug' => 'agate-choker']);
        TestBrowsable::factory()->create(['name' => 'Silver Ring', 'slug' => 'silver-ring']);

        $results = $this->list(TestBrowsable::class, ['search' => 'Agate']);

        $this->assertCount(1, $results);
        $this->assertSame('agate-choker', $results->first()->slug);
    }

    #[Test]
    public function searches_a_translation_backed_catalog_by_name(): void
    {
        $match = TestTranslatableBrowsable::factory()->create(['slug' => 'agate-choker']);
        $match->setTranslation('name', 'en', 'Agate Choker');

        $other = TestTranslatableBrowsable::factory()->create(['slug' => 'silver-ring']);
        $other->setTranslation('name', 'en', 'Silver Ring');

        $results = $this->list(TestTranslatableBrowsable::class, ['search' => 'Agate']);

        $this->assertCount(1, $results);
        $this->assertSame('agate-choker', $results->first()->slug);
    }

    #[Test]
    public function searches_a_translation_backed_catalog_by_description(): void
    {
        $match = TestTranslatableBrowsable::factory()->create(['slug' => 'macrame-piece']);
        $match->setTranslation('description', 'en', 'Delicate macrame in ivory.');

        TestTranslatableBrowsable::factory()->create(['slug' => 'plain-piece']);

        $results = $this->list(TestTranslatableBrowsable::class, ['search' => 'macrame']);

        $this->assertCount(1, $results);
        $this->assertSame('macrame-piece', $results->first()->slug);
    }

    #[Test]
    public function sorts_a_column_backed_catalog_by_name(): void
    {
        TestBrowsable::factory()->create(['name' => 'Zircon', 'slug' => 'zircon']);
        TestBrowsable::factory()->create(['name' => 'Agate', 'slug' => 'agate']);

        $results = $this->list(TestBrowsable::class, ['sort' => 'name']);

        $this->assertSame(['agate', 'zircon'], $results->pluck('slug')->all());
    }

    #[Test]
    public function sorts_a_translation_backed_catalog_by_name(): void
    {
        $z = TestTranslatableBrowsable::factory()->create(['slug' => 'zircon']);
        $z->setTranslation('name', 'en', 'Zircon');

        $a = TestTranslatableBrowsable::factory()->create(['slug' => 'agate']);
        $a->setTranslation('name', 'en', 'Agate');

        $results = $this->list(TestTranslatableBrowsable::class, ['sort' => 'name']);

        $this->assertSame(['agate', 'zircon'], $results->pluck('slug')->all());
    }

    /**
     * Rows are inserted in ASCENDING name order on purpose, so a descending
     * sort must reverse them. Insert them the other way round and this passes
     * without sorting at all — which is exactly what the broken implementation
     * did on SQLite (see the class note): `ORDER BY 'name'` on a constant is a
     * no-op that preserves insertion order.
     */
    #[Test]
    public function sorts_descending_on_a_leading_minus(): void
    {
        $a = TestTranslatableBrowsable::factory()->create(['slug' => 'agate']);
        $a->setTranslation('name', 'en', 'Agate');

        $z = TestTranslatableBrowsable::factory()->create(['slug' => 'zircon']);
        $z->setTranslation('name', 'en', 'Zircon');

        $results = $this->list(TestTranslatableBrowsable::class, ['sort' => '-name']);

        $this->assertSame(['zircon', 'agate'], $results->pluck('slug')->all());
    }

    /**
     * Both halves matter. The empty assertion guards the OR group this action
     * builds — an empty group matches every row, so a term matching nothing
     * must not return the catalogue. The matching assertion is what stops the
     * test passing on a search that silently matches nothing ever.
     */
    #[Test]
    public function a_non_matching_search_returns_nothing_but_a_matching_one_still_works(): void
    {
        $item = TestTranslatableBrowsable::factory()->create(['slug' => 'agate-choker']);
        $item->setTranslation('name', 'en', 'Agate Choker');

        TestTranslatableBrowsable::factory()->create(['slug' => 'silver-ring']);

        $this->assertCount(0, $this->list(TestTranslatableBrowsable::class, ['search' => 'zzzz']));
        $this->assertCount(1, $this->list(TestTranslatableBrowsable::class, ['search' => 'Agate']));
    }

    /**
     * @param  class-string  $modelClass
     * @param  array<string, string>  $query
     */
    private function list(string $modelClass, array $query): \Illuminate\Support\Collection
    {
        $paginator = app(ListBrowsables::class)($modelClass, Request::create('/', 'GET', $query));

        return collect($paginator->items());
    }
}
