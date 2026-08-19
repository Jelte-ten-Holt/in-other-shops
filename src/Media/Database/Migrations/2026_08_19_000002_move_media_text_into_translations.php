<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InOtherShops\Media\Media;

/*
 * Move `media.alt` and `media.description` out of columns and into the
 * `translations` table, one row per locale.
 *
 * Both fields are prose shown to a reader, so on a multi-locale storefront a
 * single column is wrong by construction: one photo, shared across every
 * language edition of the record it hangs on, could only ever carry one
 * language's words. That is fine for a consumer whose language editions are
 * separate rows owning separate media (in-other-worlds), and broken for one
 * whose catalog shares a row across locales (bianka).
 *
 * Existing values are carried into the consumer's default locale first, so
 * nothing an editor already wrote is lost; the columns are dropped only after
 * the backfill has run. Both halves are plain DML plus a two-column DROP —
 * no table rebuild, no foreign keys involved.
 *
 * Note the DDL is not transactional on MySQL: if the drop fails after the
 * backfill succeeds, re-running the migration would insert nothing new
 * (the upsert is keyed on the translations unique index) and retry the drop.
 */
return new class extends Migration
{
    private const FIELDS = ['alt', 'description'];

    public function up(): void
    {
        $present = array_values(array_filter(
            self::FIELDS,
            fn (string $field): bool => Schema::hasColumn('media', $field),
        ));

        if ($present === []) {
            return;
        }

        $this->backfill($present, config('translation.default', 'en'));

        Schema::table('media', function (Blueprint $table) use ($present): void {
            $table->dropColumn($present);
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->string('alt')->nullable();
            $table->text('description')->nullable();
        });

        $locale = config('translation.default', 'en');

        DB::table('translations')
            ->where('translatable_type', $this->morphAlias())
            ->whereIn('field', self::FIELDS)
            ->where('locale', $locale)
            ->orderBy('id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('media')
                        ->where('id', $row->translatable_id)
                        ->update([$row->field => $row->value]);
                }
            });

        DB::table('translations')
            ->where('translatable_type', $this->morphAlias())
            ->whereIn('field', self::FIELDS)
            ->delete();
    }

    /**
     * @param  list<string>  $fields
     */
    private function backfill(array $fields, string $locale): void
    {
        $morph = $this->morphAlias();
        $now = now();

        DB::table('media')
            ->select(array_merge(['id'], $fields))
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($fields, $locale, $morph, $now): void {
                $insert = [];

                foreach ($rows as $row) {
                    foreach ($fields as $field) {
                        $value = $row->{$field} ?? null;

                        if ($value === null || $value === '') {
                            continue;
                        }

                        $insert[] = [
                            'translatable_type' => $morph,
                            'translatable_id' => $row->id,
                            'locale' => $locale,
                            'field' => $field,
                            'value' => $value,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($insert !== []) {
                    // Keyed on translations_unique, so a re-run after a failed
                    // drop updates in place instead of colliding.
                    DB::table('translations')->upsert(
                        $insert,
                        ['translatable_type', 'translatable_id', 'locale', 'field'],
                        ['value', 'updated_at'],
                    );
                }
            });
    }

    private function morphAlias(): string
    {
        $model = Media::media();

        return (new $model)->getMorphClass();
    }
};
