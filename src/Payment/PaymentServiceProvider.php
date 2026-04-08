<?php

declare(strict_types=1);

namespace InOtherShops\Payment;

use InOtherShops\Payment\Contracts\PaymentGateway;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

final class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/payment.php', 'payment');

        $this->app->bind(PaymentGateway::class, function () {
            $gateway = config('payment.gateway');

            if ($gateway === null) {
                throw new \RuntimeException('No payment gateway configured. Set PAYMENT_GATEWAY in your .env file.');
            }

            return $this->app->make($gateway);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        Relation::morphMap([
            'payment' => Payment::payment()::class,
            'payment_profile' => Payment::paymentProfile()::class,
        ]);

        $this->publishes([
            __DIR__.'/config/payment.php' => config_path('payment.php'),
        ], 'payment-config');
    }
}
