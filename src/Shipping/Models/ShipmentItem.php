<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Shipping\Database\Factories\ShipmentItemFactory;
use InOtherShops\Shipping\Shipping;

class ShipmentItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new ShipmentItemFactory;
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipping::shipment());
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(Commerce::orderLine(), 'order_line_id');
    }
}
