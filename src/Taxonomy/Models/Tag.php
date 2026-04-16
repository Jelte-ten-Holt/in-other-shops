<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Models;

use InOtherShops\Translation\Concerns\InteractsWithTranslations;
use InOtherShops\Translation\Contracts\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model implements HasTranslations
{
    use InteractsWithTranslations;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return array<string> */
    public function translatableFields(): array
    {
        return ['name'];
    }
}
