<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Models;

use InOtherShops\Payment\Database\Factories\PaymentProfileFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentProfile extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new PaymentProfileFactory;
    }

    protected function casts(): array
    {
        return [
            'gateway_data' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function profileable(): MorphTo
    {
        return $this->morphTo();
    }
}
