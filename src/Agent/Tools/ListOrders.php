<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Tools;

use InOtherShops\Agent\AgentTool;
use InOtherShops\Agent\Support\PaginationParams;
use InOtherShops\Agent\Support\ResolveCallerCustomerId;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class ListOrders extends AgentTool
{
    public static function identifier(): string
    {
        return 'list_orders';
    }

    public static function displayName(): string
    {
        return 'List orders';
    }

    public function description(): string
    {
        return 'List orders with optional status and created-at date-range filters. Returns a summary per order, newest first.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases()),
                    'description' => 'Filter by order status.',
                ],
                'from' => [
                    'type' => 'string',
                    'format' => 'date',
                    'description' => 'Include only orders created on or after this date (YYYY-MM-DD).',
                ],
                'to' => [
                    'type' => 'string',
                    'format' => 'date',
                    'description' => 'Include only orders created on or before this date (YYYY-MM-DD).',
                ],
                'page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                ],
                'per_page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function __invoke(array $arguments): array
    {
        // Floors per_page at 1 (shared clamp) where this tool previously
        // let 0/negative through to paginate() — invalid input now degrades
        // to the smallest page instead of erroring.
        $pagination = PaginationParams::fromArguments($arguments, maxPerPage: 100, defaultPerPage: 25);

        $query = Commerce::order()::query()
            ->withCount('lines')
            ->orderByDesc('created_at');

        $this->scopeToCaller($query);
        $this->applyStatusFilter($query, $arguments);
        $this->applyDateRange($query, $arguments);

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate(perPage: $pagination->perPage, page: $pagination->page);

        return [
            'ok' => true,
            'data' => $paginator->getCollection()
                ->map(fn (Model $order) => $this->summariseOrder($order))
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    private function scopeToCaller(Builder $query): void
    {
        if ($this->isAdmin()) {
            return;
        }

        $customerId = (new ResolveCallerCustomerId)($this->currentUser());

        if ($customerId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('customer_id', $customerId);
    }

    /** @param array<string, mixed> $arguments */
    private function applyStatusFilter($query, array $arguments): void
    {
        if (! isset($arguments['status']) || ! is_string($arguments['status'])) {
            return;
        }

        $status = OrderStatus::tryFrom($arguments['status']);

        if ($status === null) {
            throw new InvalidArgumentException('Unknown order status "'.$arguments['status'].'".');
        }

        $query->where('status', $status->value);
    }

    /** @param array<string, mixed> $arguments */
    private function applyDateRange($query, array $arguments): void
    {
        if (isset($arguments['from']) && is_string($arguments['from']) && $arguments['from'] !== '') {
            $query->whereDate('created_at', '>=', $arguments['from']);
        }

        if (isset($arguments['to']) && is_string($arguments['to']) && $arguments['to'] !== '') {
            $query->whereDate('created_at', '<=', $arguments['to']);
        }
    }

    private function summariseOrder(Model $order): array
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
            'line_count' => $order->getAttribute('lines_count'),
            'created_at' => $order->getAttribute('created_at')?->toIso8601String(),
        ];
    }
}
