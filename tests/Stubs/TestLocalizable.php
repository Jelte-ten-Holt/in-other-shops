<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use InOtherShops\Translation\Concerns\InteractsWithLocaleGroup;
use InOtherShops\Translation\Contracts\HasLocaleGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class TestLocalizable extends Model implements HasLocaleGroup
{
    use HasFactory;
    use InteractsWithLocaleGroup;

    protected $guarded = [];

    protected $table = 'test_localizables';

    protected static function newFactory(): Factory
    {
        return new TestLocalizableFactory;
    }
}
