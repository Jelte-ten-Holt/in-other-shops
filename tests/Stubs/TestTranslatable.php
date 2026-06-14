<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use InOtherShops\Translation\Concerns\InteractsWithTranslations;
use InOtherShops\Translation\Contracts\HasTranslations;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class TestTranslatable extends Model implements HasTranslations
{
    use HasFactory;
    use InteractsWithTranslations;

    protected $guarded = [];

    protected $table = 'test_translatables';

    /** @return array<string> */
    public function translatableFields(): array
    {
        return ['name', 'description'];
    }

    protected static function newFactory(): Factory
    {
        return new TestTranslatableFactory;
    }

    protected function casts(): array
    {
        return [];
    }
}
