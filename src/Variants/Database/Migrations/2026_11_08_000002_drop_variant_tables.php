<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InOtherShops\Variants\Exceptions\VariantStillReferencedException;

/**
 * Drops the Variant half of the Variants domain (complexity cleanup release 2,
 * PR 4). The package's first drop migration: the create migrations are deleted
 * and this one runs once, forward only.
 *
 * 1. Refuse while a cart line or an order line points at a `variant`: the alias
 *    is gone after this release and the morph map is required, so such a line
 *    could no longer load.
 * 2. Delete what a variant owned: prices, stock items (their movements and
 *    reservations cascade), media links and the media rows nothing else links
 *    to, translations, category and tag links.
 * 3. Drop the pivots, then `variants` — MySQL refuses to drop a table another
 *    one still references.
 *
 * Every step is guarded, so a run that stopped half-way (MySQL DDL does not
 * roll back) finishes on the next boot. On a fresh install none of the tables
 * exist and nothing happens.
 */
return new class extends Migration
{
    private const string ALIAS = 'variant';

    /** table => morph column prefix, for lines that must not point at a variant */
    private const array LINES = [
        'cart_items' => 'cartable',
        'order_lines' => 'orderable',
    ];

    /** table => morph column prefix, for rows a variant owned */
    private const array OWNED = [
        'prices' => 'priceable',
        'stock_items' => 'stockable',
        'categorizables' => 'categorizable',
        'taggables' => 'taggable',
    ];

    public function up(): void
    {
        if (Schema::hasTable('variants')) {
            $this->refuseWhileLinesPointAtAVariant();
            // Row deletes roll back together, unlike the drops below.
            DB::transaction(fn () => $this->deleteOwnedRows());
        }

        Schema::dropIfExists('option_value_variant');
        Schema::dropIfExists('optionables');
        Schema::dropIfExists('variants');
    }

    public function down(): void
    {
        // Forward only: the Variant model is gone.
    }

    private function refuseWhileLinesPointAtAVariant(): void
    {
        $counts = [];

        foreach (self::LINES as $table => $morph) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $count = DB::table($table)->where("{$morph}_type", self::ALIAS)->count();

            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        if ($counts !== []) {
            throw VariantStillReferencedException::by($counts);
        }
    }

    private function deleteOwnedRows(): void
    {
        $this->deleteVariantMedia();

        foreach (self::OWNED as $table => $morph) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where("{$morph}_type", self::ALIAS)->delete();
            }
        }

        if (Schema::hasTable('translations')) {
            DB::table('translations')->where('translatable_type', self::ALIAS)->delete();
        }
    }

    /**
     * A variant's media links go, and so does each media row no other parent
     * still links to, as DeleteVariant did. The files stay on disk until
     * `media:prune` finds no row pointing at them.
     */
    private function deleteVariantMedia(): void
    {
        if (! Schema::hasTable('mediables') || ! Schema::hasTable('media')) {
            return;
        }

        $mediaIds = DB::table('mediables')->where('mediable_type', self::ALIAS)->distinct()->pluck('media_id')->all();

        DB::table('mediables')->where('mediable_type', self::ALIAS)->delete();

        if ($mediaIds !== []) {
            DB::table('media')
                ->whereIn('id', $mediaIds)
                ->whereNotExists(fn ($links) => $links->from('mediables')->whereColumn('mediables.media_id', 'media.id'))
                ->delete();
        }
    }
};
