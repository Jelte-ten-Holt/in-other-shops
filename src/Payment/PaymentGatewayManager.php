<?php

declare(strict_types=1);

namespace InOtherShops\Payment;

use InOtherShops\Payment\Contracts\PaymentGateway;
use Closure;
use InvalidArgumentException;

/**
 * Registry of payment gateway drivers.
 *
 * Drivers register themselves via {@see extend()} — typically from a per-driver
 * service provider that only runs when its optional dependency is available
 * (`class_exists(\Stripe\StripeClient::class)` + relevant config, etc.).
 *
 * Actions resolve the gateway per-payment by name (e.g. from `payments.gateway`
 * column or the webhook route segment) rather than through a single injected
 * `PaymentGateway` binding.
 */
final class PaymentGatewayManager
{
    /** @var array<string, Closure(): PaymentGateway> */
    private array $factories = [];

    /** @var array<string, PaymentGateway> */
    private array $resolved = [];

    /**
     * @param  Closure(): PaymentGateway  $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->factories[$name] = $factory;
    }

    public function gateway(string $name): PaymentGateway
    {
        return $this->resolved[$name] ??= $this->resolve($name);
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    /** @return array<string> */
    public function registered(): array
    {
        return array_keys($this->factories);
    }

    private function resolve(string $name): PaymentGateway
    {
        if (! isset($this->factories[$name])) {
            $available = empty($this->factories)
                ? 'none registered'
                : implode(', ', $this->registered());

            throw new InvalidArgumentException(
                "Payment gateway [{$name}] is not registered. Available: {$available}.",
            );
        }

        return ($this->factories[$name])();
    }
}
