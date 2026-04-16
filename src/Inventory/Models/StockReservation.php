<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Models;

use InOtherShops\Inventory\Database\Factories\StockReservationFactory;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Inventory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockReservation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new StockReservationFactory;
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'status' => ReservationStatus::class,
            'reserved_until' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(Inventory::stockItem());
    }

    public function reserveMovement(): BelongsTo
    {
        return $this->belongsTo(Inventory::stockMovement(), 'reserve_movement_id');
    }

    public function releaseMovement(): BelongsTo
    {
        return $this->belongsTo(Inventory::stockMovement(), 'release_movement_id');
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
        return $this->status === ReservationStatus::Pending
            && $this->reserved_until !== null
            && $this->reserved_until->isPast();
    }
}
