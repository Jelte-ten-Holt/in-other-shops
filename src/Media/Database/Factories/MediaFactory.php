<?php

declare(strict_types=1);

namespace InOtherShops\Media\Database\Factories;

use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Media as MediaRegistry;
use InOtherShops\Media\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
final class MediaFactory extends Factory
{
    public function modelName(): string
    {
        return MediaRegistry::media();
    }

    public function definition(): array
    {
        return [
            'disk' => 'public',
            'path' => 'images/'.fake()->uuid().'.jpg',
            'filename' => fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => fake()->numberBetween(10_000, 500_000),
            'alt' => fake()->sentence(3),
            'type' => MediaType::Upload->value,
            'url' => null,
        ];
    }

    public function external(): static
    {
        return $this->state(fn () => [
            'disk' => null,
            'path' => null,
            'type' => MediaType::External->value,
            'url' => fake()->imageUrl(),
        ]);
    }
}
