<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Database\Factories;

use InOtherShops\Payment\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEvent>
 */
final class WebhookEventFactory extends Factory
{
    protected $model = WebhookEvent::class;

    public function definition(): array
    {
        return [
            'gateway' => 'stripe',
            'event_id' => fake()->unique()->uuid(),
            'processed_at' => now(),
        ];
    }
}
