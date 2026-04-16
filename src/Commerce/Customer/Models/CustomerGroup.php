<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Customer\Models;

use InOtherShops\Commerce\Commerce;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerGroup extends Model
{
    protected $guarded = [];

    public function customers(): HasMany
    {
        return $this->hasMany(Commerce::customer());
    }
}
