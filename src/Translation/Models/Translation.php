<?php

declare(strict_types=1);

namespace InOtherShops\Translation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Translation extends Model
{
    protected $guarded = [];

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }
}
