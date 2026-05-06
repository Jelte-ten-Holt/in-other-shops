<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use InOtherShops\Media\Concerns\InteractsWithMedia;
use InOtherShops\Media\Contracts\HasMedia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class TestMediable extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;

    protected $guarded = [];

    protected $table = 'test_mediables';

    protected static function newFactory(): Factory
    {
        return new TestMediableFactory;
    }

    protected function casts(): array
    {
        return [];
    }
}
