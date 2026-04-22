<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Tools;

use InOtherShops\Agent\AgentTool;
use InOtherShops\Commerce\Commerce;
use Illuminate\Database\Eloquent\Model;

final class ShowOrder extends AgentTool
{
    public static function identifier(): string
    {
        return 'show_order';
    }

    public static function displayName(): string
    {
        return 'Show order';
    }

    public function description(): string
    {
        return 'Fetch one order by id, including its lines and a payments summary.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Order id.',
                ],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function __invoke(array $arguments): array
    {
        $id = (int) ($arguments['id'] ?? 0);

        $order = Commerce::order()::query()
            ->with(['lines', 'payments'])
            ->find($id);

        if ($order === null) {
            return [
                'found' => false,
                'id' => $id,
            ];
        }

        return [
            'found' => true,
            'data' => $this->shapeOrder($order),
        ];
    }

    private function shapeOrder(Model $order): array
    {
        return [
            'id' => $order->getKey(),
            'status' => $order->getAttribute('status')?->value,
            'currency' => $order->getAttribute('currency')?->value,
            'subtotal' => $order->getAttribute('subtotal'),
            'tax' => $order->getAttribute('tax'),
            'discount' => $order->getAttribute('discount'),
            'total' => $order->getAttribute('total'),
            'customer_id' => $order->getAttribute('customer_id'),
            'created_at' => $order->getAttribute('created_at')?->toIso8601String(),
            'lines' => $order->getRelation('lines')
                ->map(fn (Model $line) => $this->shapeLine($line))
                ->all(),
            'payments' => $this->shapePaymentsSummary($order),
        ];
    }

    private function shapeLine(Model $line): array
    {
        return [
            'id' => $line->getKey(),
            'orderable_type' => $line->getAttribute('orderable_type'),
            'orderable_id' => $line->getAttribute('orderable_id'),
            'description' => $line->getAttribute('description'),
            'sku' => $line->getAttribute('sku'),
            'unit_price' => $line->getAttribute('unit_price'),
            'quantity' => $line->getAttribute('quantity'),
            'line_total' => $line->getAttribute('line_total'),
            'currency' => $line->getAttribute('currency')?->value,
            'is_pre_order' => (bool) $line->getAttribute('is_pre_order'),
        ];
    }

    private function shapePaymentsSummary(Model $order): array
    {
        $payments = $order->getRelation('payments');

        return [
            'count' => $payments->count(),
            'total_paid' => method_exists($order, 'totalPaid') ? $order->totalPaid() : null,
            'is_paid' => method_exists($order, 'isPaid') ? $order->isPaid() : null,
            'items' => $payments->map(fn (Model $payment) => [
                'id' => $payment->getKey(),
                'status' => $payment->getAttribute('status')?->value,
                'amount' => $payment->getAttribute('amount'),
                'amount_refunded' => $payment->getAttribute('amount_refunded'),
                'currency' => $payment->getAttribute('currency')?->value,
                'created_at' => $payment->getAttribute('created_at')?->toIso8601String(),
            ])->all(),
        ];
    }
}
