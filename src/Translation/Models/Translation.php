<?php

declare(strict_types=1);

namespace InOtherShops\Translation\Models;

use InOtherShops\Translation\Database\Factories\TranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Translation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static string $factory = TranslationFactory::class;

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }
}
