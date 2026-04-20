<?php

declare(strict_types=1);

namespace InOtherShops\Media\Database\Factories;

use InOtherShops\Media\Media;
use InOtherShops\Media\Models\Mediable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mediable>
 */
final class MediableFactory extends Factory
{
    public function modelName(): string
    {
        return Media::mediable();
    }

    public function definition(): array
    {
        return [
            'media_id' => Media::media()::factory(),
            'mediable_type' => 'product',
            'mediable_id' => 1,
            'collection' => 'images',
            'position' => 0,
        ];
    }
}
