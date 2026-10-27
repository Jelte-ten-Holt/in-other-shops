<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use InOtherShops\Agent\Http\Requests\ReadPageRequest;
use InOtherShops\Agent\Http\Resources\Read\PaymentResource;
use InOtherShops\Agent\Http\Resources\Read\PurchaseOrderResource;
use InOtherShops\Agent\Http\Resources\Read\RefundResource;
use InOtherShops\Agent\Http\Resources\Read\ShipmentResource;
use InOtherShops\Agent\Http\Support\ReadFormat;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Purchasing\Purchasing;
use InOtherShops\Shipping\Shipping;

/**
 * The bookkeeping read API (in-other-books brief §12 P-5): one keyset page of
 * a resource, `{data, next, has_more}`.
 *
 * Ordered by `(updated_at, id)` and filtered past the client's cursor as a
 * tuple, with `updated_at <= until`. A payment, refund or shipment write does
 * not touch its order's timestamp, so each resource is paged on its own; each
 * item embeds what its bookkeeping event needs (the order in the first three,
 * the supplier in a purchase order). Payments and shipments are an order's
 * only: those are the ones a shop sale is made of. A row whose order is gone
 * (the morph has no foreign key) is skipped rather than failing its page,
 * which would stop the client's cursor there for good.
 *
 * The client keeps `until` behind the present by its own pull window: a row
 * stamped at second S can commit after S, and a page read up to now could
 * pass it by.
 */
final class ReadApiController
{
    public const array RESOURCES = ['payments', 'refunds', 'shipments', 'purchase_orders'];

    private const array ORDER = ['payable.customer', 'payable.lines', 'payable.addresses'];

    public function __invoke(ReadPageRequest $request, string $resource): JsonResponse
    {
        $orderAlias = (new (Commerce::order()))->getMorphClass();

        [$query, $resourceClass] = match ($resource) {
            'payments' => [
                Payment::query()
                    ->where('payable_type', $orderAlias)
                    ->whereHasMorph('payable', [Commerce::order()])
                    ->with(self::ORDER),
                PaymentResource::class,
            ],
            'refunds' => [
                Commerce::refund()::query()->with(['payment', 'order.customer', 'order.lines', 'order.addresses']),
                RefundResource::class,
            ],
            'shipments' => [
                Shipping::shipment()::query()
                    ->where('shippable_type', $orderAlias)
                    ->whereHasMorph('shippable', [Commerce::order()])
                    ->with(['items', 'shippable.customer', 'shippable.lines', 'shippable.addresses', 'shippable.shipments.items']),
                ShipmentResource::class,
            ],
            'purchase_orders' => [
                Purchasing::purchaseOrder()::query()->with(['lines', 'supplier']),
                PurchaseOrderResource::class,
            ],
        };

        $limit = $request->limit();
        $rows = $this->page($query, $request)->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        /** @var Model|null $last */
        $last = $rows->last();

        return response()->json([
            'data' => $rows->map(fn (Model $row): array => (new $resourceClass($row))->toArray($request))->values()->all(),
            'next' => $last === null ? null : ['updated_at' => ReadFormat::at($last->updated_at), 'id' => $last->getKey()],
            'has_more' => $hasMore,
        ]);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function page(Builder $query, ReadPageRequest $request): Builder
    {
        $table = $query->getModel()->getTable();
        $query->where("{$table}.updated_at", '<=', $request->until());

        if (($cursor = $request->cursor()) !== null) {
            [$at, $id] = $cursor;
            $query->where(fn (Builder $q) => $q
                ->where("{$table}.updated_at", '>', $at)
                ->orWhere(fn (Builder $q) => $q->where("{$table}.updated_at", '=', $at)->where("{$table}.id", '>', $id)));
        }

        return $query->orderBy("{$table}.updated_at")->orderBy("{$table}.id");
    }
}
