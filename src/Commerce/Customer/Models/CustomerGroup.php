<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Customer\Models;

use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Database\Factories\CustomerGroupFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerGroup extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new CustomerGroupFactory;
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Commerce::customer());
    }
}
