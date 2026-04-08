<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentProfile extends Model
{
    protected $guarded = [];

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
