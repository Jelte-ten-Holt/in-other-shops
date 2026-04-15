<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Models;

use InOtherShops\Inventory\Database\Factories\StockMovementFactory;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Inventory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new StockMovementFactory;
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reason' => StockMovementReason::class,
            'reserved_until' => 'datetime',
        ];
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(Inventory::stockItem()::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function isExpired(): bool
    {
        return $this->reserved_until !== null && $this->reserved_until->isPast();
    }
}
