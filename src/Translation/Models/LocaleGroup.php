<?php

declare(strict_types=1);

namespace InOtherShops\Translation\Models;

use InOtherShops\Translation\Database\Factories\LocaleGroupFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LocaleGroup extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new LocaleGroupFactory;
    }

    protected function casts(): array
    {
        return [
            'shares_inventory' => 'boolean',
        ];
    }
}
