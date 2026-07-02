<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Order\DTOs\RefundActor;
use InOtherShops\Commerce\Order\Events\RefundRecorded;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\Refund;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Pricing\DTOs\TaxBreakdownLine;

/**
 * Records ONE Refund row against an order and reverses the matching VAT. The
 * single source of refund records, shared by the admin flow (RefundOrder) and
 * the gateway-webhook reconciliation listener.
 *
 * Idempotent on (gateway, gateway_refund_id): a webhook echoing an admin refund
 * (or a redelivered webhook) finds the existing row and returns it without
 * re-recording or re-dispatching. `RefundRecorded` fires exactly once, on first
 * creation.
 *
 * Tax reversal is cumulative-anchored (see ReverseTax): the reversed bracket
 * tax is the delta between what this refund's cumulative should have reversed
 * and what prior refunds already reversed — so a sequence of partials reconciles
 * to the charged tax exactly.
 */
final class RecordRefund
{
    public function __construct(
        private readonly ReverseTax $reverseTax,
    ) {}

    public function __invoke(
        Order $order,
        Payment $payment,
        string $gatewayRefundId,
        int $amount,
        int $cumulativeRefunded,
        RefundActor $actor,
        ?string $reason = null,
    ): Refund {
        $existing = $this->find($payment->gateway, $gatewayRefundId);

        if ($existing !== null) {
            return $existing;
        }

        $taxSummary = $this->reverseTaxSummary($order, $amount, $cumulativeRefunded);

        try {
            $refund = DB::transaction(fn (): Refund => Commerce::refund()::query()->create([
                'order_id' => $order->getKey(),
                'payment_id' => $payment->getKey(),
                'gateway' => $payment->gateway,
                'gateway_refund_id' => $gatewayRefundId,
                'amount' => $amount,
                'tax_summary' => $taxSummary,
                'reason' => $reason,
                'actor_source' => $actor->source,
                'actor_id' => $actor->id,
                'actor_label' => $actor->label,
            ]));
        } catch (UniqueConstraintViolationException) {
            // A concurrent path (admin vs. webhook) recorded it first — return
            // theirs, don't double-record or double-dispatch.
            return $this->find($payment->gateway, $gatewayRefundId) ?? throw new UniqueConstraintViolationException('', '', [], null);
        }

        RefundRecorded::dispatch($refund);

        return $refund;
    }

    private function find(string $gateway, string $gatewayRefundId): ?Refund
    {
        /** @var Refund|null */
        return Commerce::refund()::query()
            ->where('gateway', $gateway)
            ->where('gateway_refund_id', $gatewayRefundId)
            ->first();
    }

    /**
     * @return list<array{rate_bps: int, taxable_base: int, tax: int}>
     */
    private function reverseTaxSummary(Order $order, int $amount, int $cumulativeRefunded): array
    {
        [$alreadyTax, $alreadyBase] = $this->alreadyReversed($order);

        $deltas = ($this->reverseTax)(
            originalBrackets: $order->taxSummary(),
            originalAmount: $order->total,
            cumulativeRefunded: $cumulativeRefunded,
            alreadyReversedTax: $alreadyTax,
            alreadyReversedBase: $alreadyBase,
        );

        return TaxBreakdownLine::serializeMany($deltas);
    }

    /**
     * Per-bracket tax and base already reversed by this order's prior refunds.
     *
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function alreadyReversed(Order $order): array
    {
        $tax = [];
        $base = [];

        foreach ($order->refunds()->get() as $refund) {
            foreach ($refund->taxSummary() as $line) {
                $tax[$line->rateBps] = ($tax[$line->rateBps] ?? 0) + $line->tax;
                $base[$line->rateBps] = ($base[$line->rateBps] ?? 0) + $line->taxableBase;
            }
        }

        return [$tax, $base];
    }
}
