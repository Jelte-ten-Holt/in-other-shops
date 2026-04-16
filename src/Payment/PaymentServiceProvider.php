<?php

declare(strict_types=1);

namespace InOtherShops\Payment;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

final class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/payment.php', 'payment');

        $this->app->singleton(PaymentGatewayManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        Relation::morphMap([
            'payment' => Payment::payment(),
            'payment_profile' => Payment::paymentProfile(),
        ]);

        $this->publishes([
            __DIR__.'/config/payment.php' => config_path('payment.php'),
        ], 'payment-config');
    }
}
