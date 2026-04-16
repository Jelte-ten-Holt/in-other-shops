<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Listeners;

use InOtherShops\Logging\DTOs\LogEntry;
use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\LogDispatcher;
use InOtherShops\Payment\Events\PaymentFailed;
use InOtherShops\Payment\Events\PaymentRefunded;
use InOtherShops\Payment\Events\PaymentSucceeded;
use InOtherShops\Payment\Models\Payment;
use Illuminate\Contracts\Events\Dispatcher;

final class PaymentLogSubscriber
{
    private const string CHANNEL = 'payment';

    public function __construct(
        private readonly LogDispatcher $dispatcher,
    ) {}

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            PaymentSucceeded::class => 'handlePaymentSucceeded',
            PaymentFailed::class => 'handlePaymentFailed',
            PaymentRefunded::class => 'handlePaymentRefunded',
        ];
    }

    public function handlePaymentSucceeded(PaymentSucceeded $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: "Payment succeeded on {$event->payment->gateway}.",
            context: $this->paymentContext($event->payment),
        ));
    }

    public function handlePaymentFailed(PaymentFailed $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Error,
            channel: self::CHANNEL,
            message: "Payment failed on {$event->payment->gateway}.",
            context: $this->paymentContext($event->payment),
        ));
    }

    public function handlePaymentRefunded(PaymentRefunded $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: "Payment refunded on {$event->payment->gateway}.",
            context: [
                ...$this->paymentContext($event->payment),
                'amount_refunded' => $event->payment->amount_refunded,
            ],
        ));
    }

    /** @return array<string, mixed> */
    private function paymentContext(Payment $payment): array
    {
        return [
            'payment_id' => $payment->id,
            'gateway' => $payment->gateway,
            'gateway_reference' => $payment->gateway_reference,
            'payable_type' => $payment->payable_type,
            'payable_id' => $payment->payable_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency?->value,
            'status' => $payment->status->value,
        ];
    }
}
