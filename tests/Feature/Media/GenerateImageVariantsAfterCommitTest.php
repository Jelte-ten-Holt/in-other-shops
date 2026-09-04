<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Models\Media;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * D3's `afterCommit`: bianka's admin panel wraps every save in a transaction,
 * and a job that runs before the commit finds no row. Pinned against the real
 * `sync` queue rather than the fake — `Queue::fake()` records a push the
 * moment it is made and ignores after-commit deferral, so it cannot see this.
 */
final class GenerateImageVariantsAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['media.disk' => 'public', 'queue.default' => 'sync']);
        Storage::fake('public');
    }

    #[Test]
    public function the_job_runs_only_once_the_enclosing_transaction_commits(): void
    {
        Storage::disk('public')->put('media/land.jpg', (string) file_get_contents(__DIR__.'/../../Fixtures/media/landscape-1200.jpg'));

        DB::beginTransaction();
        Media::create([
            'type' => MediaType::Upload, 'disk' => 'public', 'path' => 'media/land.jpg',
            'filename' => 'land.jpg', 'mime_type' => 'image/jpeg', 'size' => 1,
        ]);
        $this->assertFalse(Storage::disk('public')->exists('media/land-w400.webp'), 'the job ran before the row was committed');

        DB::commit();

        $this->assertTrue(Storage::disk('public')->exists('media/land-w400.webp'));
        $this->assertSame([400, 800], array_keys(Media::sole()->variants));
    }
}
