<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Concerns;

use InOtherShops\Commerce\Commerce;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait InteractsWithOrders
{
    public function orderLines(): MorphMany
    {
        $model = Commerce::orderLine();

        return $this->morphMany($model, 'orderable');
    }
}
