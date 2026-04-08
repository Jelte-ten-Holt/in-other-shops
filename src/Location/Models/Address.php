<?php

declare(strict_types=1);

namespace InOtherShops\Location\Models;

use InOtherShops\Location\Enums\AddressType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Address extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => AddressType::class,
        ];
    }

    public function addressable(): MorphTo
    {
        return $this->morphTo();
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function oneLine(): string
    {
        return collect([
            $this->line_1,
            $this->line_2,
            $this->city,
            $this->state,
            $this->postal_code,
            $this->country_code,
        ])->filter()->implode(', ');
    }
}
