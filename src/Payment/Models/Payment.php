<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Models;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Database\Factories\PaymentFactory;
use InOtherShops\Payment\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new PaymentFactory;
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'amount_refunded' => 'integer',
            'currency' => Currency::class,
            'status' => PaymentStatus::class,
            'gateway_data' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isSuccessful(): bool
    {
        return $this->status === PaymentStatus::Succeeded;
    }

    public function isRefunded(): bool
    {
        return $this->status === PaymentStatus::Refunded;
    }

    public function isPartiallyRefunded(): bool
    {
        return $this->status === PaymentStatus::PartiallyRefunded;
    }
}
