<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Media;

use InOtherShops\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * The migration runs once, against columns that already hold an editor's work
 * on every deployed consumer. Reconstruct the pre-migration shape and run it
 * for real, rather than trusting that a one-shot data move is obviously right.
 */
final class MediaTextBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require __DIR__.'/../../../src/Media/Database/Migrations/2026_08_19_000002_move_media_text_into_translations.php';
    }

    private function restoreColumns(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->string('alt')->nullable();
            $table->text('description')->nullable();
        });
    }

    private function seedRow(?string $alt, ?string $description): int
    {
        return DB::table('media')->insertGetId([
            'disk' => 'public',
            'path' => 'images/x.jpg',
            'filename' => 'x.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1000,
            'type' => 'upload',
            'alt' => $alt,
            'description' => $description,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_carries_existing_text_into_the_default_locale_and_drops_the_columns(): void
    {
        config(['translation.default' => 'es']);
        $this->restoreColumns();

        $id = $this->seedRow('Collar de plata', 'Plata de ley, cadena de 18 cm.');

        $this->migration()->up();

        $this->assertDatabaseHas('translations', [
            'translatable_type' => 'media',
            'translatable_id' => $id,
            'locale' => 'es',
            'field' => 'alt',
            'value' => 'Collar de plata',
        ]);
        $this->assertDatabaseHas('translations', [
            'translatable_type' => 'media',
            'translatable_id' => $id,
            'locale' => 'es',
            'field' => 'description',
            'value' => 'Plata de ley, cadena de 18 cm.',
        ]);

        $this->assertFalse(Schema::hasColumn('media', 'alt'));
        $this->assertFalse(Schema::hasColumn('media', 'description'));
    }

    #[Test]
    public function it_writes_no_row_for_a_column_that_was_empty(): void
    {
        $this->restoreColumns();

        $id = $this->seedRow(null, '');

        $this->migration()->up();

        // A blank column is the absence of a value, not a value that is blank.
        // Storing it would make every untranslated caption render as an empty
        // figcaption instead of falling back.
        $this->assertDatabaseMissing('translations', [
            'translatable_type' => 'media',
            'translatable_id' => $id,
        ]);
    }

    #[Test]
    public function it_is_safe_to_re_run_after_a_failed_drop(): void
    {
        $this->restoreColumns();

        $id = $this->seedRow('Alt text', null);

        // The backfill is DML and the drop is DDL; on MySQL the two are not in
        // one transaction, so a re-run has to be an upsert, not an insert.
        $this->migration()->up();
        $this->restoreColumns();
        DB::table('media')->where('id', $id)->update(['alt' => 'Alt text']);
        $this->migration()->up();

        $this->assertSame(1, DB::table('translations')
            ->where('translatable_type', 'media')
            ->where('translatable_id', $id)
            ->where('field', 'alt')
            ->count());
    }

    #[Test]
    public function it_is_a_no_op_when_the_columns_are_already_gone(): void
    {
        $this->migration()->up();

        $this->assertFalse(Schema::hasColumn('media', 'alt'));
    }
}
