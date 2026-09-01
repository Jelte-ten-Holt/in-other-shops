<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for every test stub.
 *
 * The stubs stand in for consumer catalog models so the package's own suite can
 * exercise its `Has*` contracts without depending on in-other-worlds or any
 * other consumer. One base + thin `final` subclasses ({@see StubModels.php}) +
 * per-capability column/cast fragments ({@see StubColumns}) + one factory
 * ({@see StubModelFactory}) replace what used to be 14 model/factory/migration
 * triples.
 *
 * A stub's **capabilities** are its single source of truth: the same list drives
 * its table columns (StubColumns), its casts (below), its factory defaults
 * (StubModelFactory) and its morph alias ({@see stubClasses()}). Because the four
 * derive from one declaration, they cannot silently drift apart — the trust
 * hazard that STUB-1/STUB-2 flagged.
 */
abstract class StubModel extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * Capability keys this stub composes. Each key maps to a column fragment, a
     * cast set, and a block of factory defaults. Declared per `final` subclass.
     *
     * @return list<string>
     */
    abstract public static function capabilities(): array;

    /**
     * Morph alias => stub class. Drives both the consolidated stub migration and
     * the `TestCase` morph map, so each stub is registered in exactly one place.
     *
     * @return array<string, class-string<self>>
     */
    public static function stubClasses(): array
    {
        return [
            'test_stockable' => TestStockable::class,
            'test_payable' => TestPayable::class,
            'test_cartable' => TestCartable::class,
            'test_translatable_cartable' => TestTranslatableCartable::class,
            'test_shippable_cartable' => TestShippableCartable::class,
            'test_stockable_cartable' => TestStockableCartable::class,
            'test_browsable' => TestBrowsable::class,
            'test_translatable_browsable' => TestTranslatableBrowsable::class,
            'test_localizable' => TestLocalizable::class,
            'test_stockable_localizable' => TestStockableLocalizable::class,
            'test_mediable' => TestMediable::class,
            'test_editable' => TestEditable::class,
            'test_priceable' => TestPriceable::class,
            'test_purchasable' => TestPurchasable::class,
            'test_taxonomized' => TestTaxonomized::class,
            'test_translatable' => TestTranslatable::class,
            'test_variantable' => TestVariantable::class,
        ];
    }

    protected static function newFactory(): Factory
    {
        return StubModelFactory::ofModel(static::class);
    }

    protected function casts(): array
    {
        $casts = [];

        foreach (static::capabilities() as $capability) {
            $casts = array_merge($casts, StubColumns::castsFor($capability));
        }

        return $casts;
    }
}
