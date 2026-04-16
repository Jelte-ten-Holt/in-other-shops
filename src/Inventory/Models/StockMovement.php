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

/**
 * Append-only ledger entry for a single stock delta.
 *
 * Rows are never updated after insert — `const UPDATED_AT = null` enforces
 * this at the ORM layer. Reservation lifecycle (pending/confirmed/released)
 * lives on {@see StockReservation}, not here.
 */
class StockMovement extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

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
        ];
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(Inventory::stockItem());
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
