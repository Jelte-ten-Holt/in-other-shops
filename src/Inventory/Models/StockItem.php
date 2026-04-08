<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Models;

use InOtherShops\Inventory\Inventory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'stock_level' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }

    public function stockable(): MorphTo
    {
        return $this->morphTo();
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Inventory::stockMovement()::class);
    }

    public function isLowStock(): bool
    {
        if ($this->low_stock_threshold === null) {
            return false;
        }

        return $this->stock_level <= $this->low_stock_threshold;
    }
}
