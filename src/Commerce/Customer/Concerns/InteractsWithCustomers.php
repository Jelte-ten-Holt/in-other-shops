<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Customer\Concerns;

use InOtherShops\Commerce\Commerce;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait InteractsWithCustomers
{
    public function customer(): MorphOne
    {
        $model = Commerce::customer();

        return $this->morphOne($model, 'authenticatable');
    }
}
