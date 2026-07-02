<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Models;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Purchasing\Database\Factories\SupplierFactory;
use InOtherShops\Purchasing\Purchasing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static string $factory = SupplierFactory::class;

    protected function casts(): array
    {
        return [
            'default_currency' => Currency::class,
        ];
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(Purchasing::purchaseOrder());
    }
}
