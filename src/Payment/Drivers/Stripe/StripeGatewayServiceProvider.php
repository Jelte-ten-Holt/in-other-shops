<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Drivers\Stripe;

use InOtherShops\Payment\PaymentGatewayManager;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

/**
 * Registers the Stripe driver with {@see PaymentGatewayManager} only when
 * `stripe/stripe-php` is installed AND `payment.gateways.stripe.secret` is
 * configured. Missing either condition, this provider is a no-op — consumers
 * without Stripe see no registration, no errors.
 *
 * This is the precedent for optional-dependency drivers.
 */
final class StripeGatewayServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! class_exists(StripeClient::class)) {
            return;
        }

        $secret = config('payment.gateways.stripe.secret');
        $webhookSecret = config('payment.gateways.stripe.webhook_secret');

        if (empty($secret) || empty($webhookSecret)) {
            return;
        }

        $manager = $this->app->make(PaymentGatewayManager::class);

        $manager->extend('stripe', fn (): StripePaymentGateway => new StripePaymentGateway(
            client: new StripeClient($secret),
            webhookSecret: $webhookSecret,
        ));
    }
}
