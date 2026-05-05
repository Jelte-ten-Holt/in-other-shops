<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Translation;

use InOtherShops\Tests\Stubs\TestLocalizable;
use InOtherShops\Tests\TestCase;
use InOtherShops\Translation\Models\LocaleGroup;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class LocaleGroupTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function monolingual_row_has_no_group_and_no_siblings(): void
    {
        $row = TestLocalizable::factory()->create(['locale' => 'en']);

        $this->assertNull($row->locale_group_id);
        $this->assertNull($row->localeGroup);
        $this->assertCount(0, $row->siblings()->get());
    }

    #[Test]
    public function siblings_returns_other_locale_rows_in_the_same_group(): void
    {
        $group = LocaleGroup::factory()->create();
        $en = TestLocalizable::factory()->create(['locale' => 'en', 'locale_group_id' => $group->id]);
        $de = TestLocalizable::factory()->create(['locale' => 'de', 'locale_group_id' => $group->id]);
        $fr = TestLocalizable::factory()->create(['locale' => 'fr', 'locale_group_id' => $group->id]);

        $siblings = $en->siblings()->get();

        $this->assertCount(2, $siblings);
        $this->assertEqualsCanonicalizing(
            [$de->id, $fr->id],
            $siblings->pluck('id')->all(),
        );
    }

    #[Test]
    public function siblings_query_excludes_self(): void
    {
        $group = LocaleGroup::factory()->create();
        $en = TestLocalizable::factory()->create(['locale' => 'en', 'locale_group_id' => $group->id]);
        TestLocalizable::factory()->create(['locale' => 'de', 'locale_group_id' => $group->id]);

        $this->assertFalse(
            $en->siblings()->get()->contains('id', $en->id),
        );
    }

    #[Test]
    public function in_locale_returns_self_when_locale_matches(): void
    {
        $row = TestLocalizable::factory()->create(['locale' => 'en']);

        $this->assertSame($row->id, $row->inLocale('en')?->id);
    }

    #[Test]
    public function in_locale_returns_sibling_when_locale_differs(): void
    {
        $group = LocaleGroup::factory()->create();
        $en = TestLocalizable::factory()->create(['locale' => 'en', 'locale_group_id' => $group->id]);
        $de = TestLocalizable::factory()->create(['locale' => 'de', 'locale_group_id' => $group->id]);

        $this->assertSame($de->id, $en->inLocale('de')?->id);
    }

    #[Test]
    public function in_locale_returns_null_when_monolingual_and_locale_differs(): void
    {
        $row = TestLocalizable::factory()->create(['locale' => 'en']);

        $this->assertNull($row->inLocale('de'));
    }

    #[Test]
    public function in_locale_returns_null_when_sibling_for_locale_does_not_exist(): void
    {
        $group = LocaleGroup::factory()->create();
        $en = TestLocalizable::factory()->create(['locale' => 'en', 'locale_group_id' => $group->id]);
        TestLocalizable::factory()->create(['locale' => 'de', 'locale_group_id' => $group->id]);

        $this->assertNull($en->inLocale('fr'));
    }

    #[Test]
    public function locale_method_returns_column_value(): void
    {
        $row = TestLocalizable::factory()->create(['locale' => 'de']);

        $this->assertSame('de', $row->locale());
    }

    #[Test]
    public function locale_method_falls_back_to_app_locale_when_column_is_null(): void
    {
        config()->set('app.locale', 'en');
        $row = TestLocalizable::factory()->create(['locale' => null]);

        $this->assertSame('en', $row->locale());
    }

    #[Test]
    public function for_locale_scope_filters_by_locale(): void
    {
        $group = LocaleGroup::factory()->create();
        TestLocalizable::factory()->create(['locale' => 'en', 'locale_group_id' => $group->id]);
        TestLocalizable::factory()->create(['locale' => 'de', 'locale_group_id' => $group->id]);
        TestLocalizable::factory()->create(['locale' => 'fr']);

        $de = TestLocalizable::query()->forLocale('de')->get();

        $this->assertCount(1, $de);
        $this->assertSame('de', $de->first()->locale);
    }

    #[Test]
    public function monolingual_scope_returns_only_ungrouped_rows(): void
    {
        $group = LocaleGroup::factory()->create();
        TestLocalizable::factory()->create(['locale' => 'en', 'locale_group_id' => $group->id]);
        $solo = TestLocalizable::factory()->create(['locale' => 'en', 'locale_group_id' => null]);

        $monolingual = TestLocalizable::query()->monolingual()->get();

        $this->assertCount(1, $monolingual);
        $this->assertSame($solo->id, $monolingual->first()->id);
    }

    #[Test]
    public function locale_group_belongs_to_relation_resolves(): void
    {
        $group = LocaleGroup::factory()->create();
        $row = TestLocalizable::factory()->create(['locale' => 'en', 'locale_group_id' => $group->id]);

        $this->assertSame($group->id, $row->localeGroup->id);
    }

    #[Test]
    public function shares_inventory_defaults_to_false(): void
    {
        $group = LocaleGroup::factory()->create();

        $this->assertFalse($group->shares_inventory);
    }

    #[Test]
    public function shares_inventory_can_be_toggled(): void
    {
        $group = LocaleGroup::factory()->sharingInventory()->create();

        $this->assertTrue($group->shares_inventory);
    }

    #[Test]
    public function unique_constraint_prevents_two_siblings_in_same_locale(): void
    {
        $group = LocaleGroup::factory()->create();
        TestLocalizable::factory()->create(['locale' => 'en', 'locale_group_id' => $group->id]);

        $this->expectException(QueryException::class);

        TestLocalizable::factory()->create(['locale' => 'en', 'locale_group_id' => $group->id]);
    }
}
