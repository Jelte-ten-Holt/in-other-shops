<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Concerns;

use InOtherShops\Commerce\Commerce;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait InteractsWithCart
{
    public function cartItems(): MorphMany
    {
        $model = Commerce::cartItem();

        return $this->morphMany($model::class, 'cartable');
    }

    public function getCartableLabel(): string
    {
        return $this->name;
    }

    public function getCartableDescription(): ?string
    {
        return $this->description ?? null;
    }
}
