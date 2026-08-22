<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Actions;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Customer\Models\Customer;
use InOtherShops\Commerce\Exceptions\CommerceException;
use InOtherShops\Commerce\Order\Contracts\HasOrders;
use InOtherShops\Commerce\Order\Contracts\OrderNumberGenerator;
use InOtherShops\Commerce\Order\DTOs\ShippingSnapshot;
use InOtherShops\Commerce\Order\DTOs\TaxSnapshot;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Events\OrderCreated;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Support\RandomOrderNumberGenerator;
use InOtherShops\Location\Enums\AddressType;
use InOtherShops\Location\Models\Address;
use InOtherShops\Pricing\Actions\ApplyVoucher;
use InOtherShops\Pricing\DTOs\PriceBreakdown;
use InOtherShops\Pricing\DTOs\TaxBreakdownLine;
use InOtherShops\Tax\Contracts\HasTaxCategory;
use InOtherShops\Tax\Enums\TaxCategory;

/**
 * Create an Order with snapshotted pricing, order lines, and addresses,
 * inside a single transaction. Wires voucher usage through {@see ApplyVoucher}
 * so an oversold voucher race rolls back the order too.
 *
 * Intended to be composed by a consumer-level checkout FlowChain step:
 * reserve stock, initiate payment, and create order all live in the
 * same outer transaction so partial failures are impossible.
 */
final class CreateOrder
{
    public function __construct(
        private readonly Container $container,
        private readonly ApplyVoucher $applyVoucher,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, line_1: string, line_2?: ?string, city: string, state?: ?string, postal_code: string, country_code: string, phone?: ?string}  $billingAddress
     * @param  array{first_name: string, last_name: string, line_1: string, line_2?: ?string, city: string, state?: ?string, postal_code: string, country_code: string, phone?: ?string}|null  $shippingAddress
     */
    public function __invoke(
        Cart $cart,
        PriceBreakdown $breakdown,
        array $billingAddress,
        ?array $shippingAddress = null,
        ?Customer $customer = null,
        ?string $guestEmail = null,
        ?TaxSnapshot $taxSnapshot = null,
        ?ShippingSnapshot $shippingSnapshot = null,
        ?string $locale = null,
    ): Order {
        $order = DB::transaction(fn (): Order => $this->build($cart, $breakdown, $billingAddress, $shippingAddress, $customer, $guestEmail, $taxSnapshot, $shippingSnapshot, $locale));

        OrderCreated::dispatch($order);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $billingAddress
     * @param  array<string, mixed>|null  $shippingAddress
     */
    private function build(Cart $cart, PriceBreakdown $breakdown, array $billingAddress, ?array $shippingAddress, ?Customer $customer, ?string $guestEmail, ?TaxSnapshot $taxSnapshot, ?ShippingSnapshot $shippingSnapshot, ?string $locale): Order
    {
        $this->commitVoucherUsage($breakdown);

        $order = $this->createOrderRecord($breakdown, $customer, $guestEmail, $taxSnapshot, $shippingSnapshot, $locale);

        $linesTotal = $this->attachLines($order, $cart, $breakdown);
        $this->assertSubtotalReconciles($linesTotal, $breakdown);

        $this->attachAddress($order, $billingAddress, $shippingAddress === null ? AddressType::ShippingAndBilling : AddressType::Billing);

        if ($shippingAddress !== null) {
            $this->attachAddress($order, $shippingAddress, AddressType::Shipping);
        }

        return $order;
    }

    /**
     * Redeem the voucher the breakdown was priced with.
     *
     * `alreadyValidated` because the breakdown reaching this action came from
     * CalculateTotal, which validated the code to arrive at `discount` — the
     * only gap is the microseconds between that and this transaction. Refusing
     * there would fail an order the shopper has already been quoted and, at
     * checkout, sent to a payment form for; the rare overshoot past `max_uses`
     * is the cheaper side of that trade. The row lock still serialises the
     * increment, so `times_used` stays truthful about the overshoot.
     *
     * A consumer that wants the strict re-check calls ApplyVoucher itself.
     */
    private function commitVoucherUsage(PriceBreakdown $breakdown): void
    {
        if ($breakdown->voucherCode === null) {
            return;
        }

        ($this->applyVoucher)(
            subtotal: $breakdown->subtotal,
            code: $breakdown->voucherCode,
            currency: $breakdown->currency,
            alreadyValidated: true,
        );
    }

    private function createOrderRecord(PriceBreakdown $breakdown, ?Customer $customer, ?string $guestEmail, ?TaxSnapshot $taxSnapshot, ?ShippingSnapshot $shippingSnapshot, ?string $locale): Order
    {
        /** @var Order */
        return Commerce::order()::query()->create([
            'order_number' => ($this->container->make($this->generatorClass()))(),
            'status' => OrderStatus::Pending,
            'currency' => $breakdown->currency,
            'subtotal' => $breakdown->subtotal,
            'tax' => $breakdown->tax,
            'tax_rate_bps' => $taxSnapshot?->rateBps,
            'tax_rate_country_code' => $taxSnapshot?->countryCode,
            'tax_summary' => TaxBreakdownLine::serializeMany($breakdown->taxBreakdown),
            'discount' => $breakdown->discount,
            // Snapshot which voucher caused the discount, so a discounted order
            // is traceable to its campaign after the voucher row is edited or
            // deleted.
            'voucher_code' => $breakdown->voucherCode,
            'total' => $breakdown->total,
            'shipping_method_identifier' => $shippingSnapshot?->methodIdentifier,
            'shipping_cost' => $shippingSnapshot?->cost ?? 0,
            'shipping_cost_currency' => $shippingSnapshot?->currency,
            'customer_id' => $customer?->getKey(),
            'email' => $guestEmail,
            'locale' => $locale,
        ]);
    }

    /** @return class-string<OrderNumberGenerator> */
    private function generatorClass(): string
    {
        /** @var class-string<OrderNumberGenerator> */
        return config(
            'commerce.order.number_generator',
            RandomOrderNumberGenerator::class,
        );
    }

    private function attachLines(Order $order, Cart $cart, PriceBreakdown $breakdown): int
    {
        $cart->loadMissing('items.cartable');

        $linesTotal = 0;

        foreach ($cart->items->values() as $index => $item) {
            $draft = $this->buildLineDraft($item, $breakdown, $index);
            $linesTotal += $draft['attributes']['line_total'];
            $this->persistLine($order, $draft);
        }

        return $linesTotal;
    }

    /**
     * Invariant (audit T1 / G2): stored order lines must sum to the priced
     * subtotal. They diverge when a stored per-line price differs from the
     * breakdown's priced unit — the quantity-tier case, where the breakdown
     * resolves a tiered unit price but the stored line falls back to the qty-1
     * price. Fail loud inside the transaction so the whole order rolls back,
     * rather than persist an order whose total ≠ sum(lines).
     *
     * Only enforced when the breakdown carries per-line data — production
     * always does (CalculateTotal builds the lines). A line-less breakdown is a
     * synthetic/legacy input there's nothing to reconcile against.
     */
    private function assertSubtotalReconciles(int $linesTotal, PriceBreakdown $breakdown): void
    {
        if ($breakdown->lines === []) {
            return;
        }

        if ($linesTotal !== $breakdown->subtotal) {
            throw CommerceException::subtotalMismatch($linesTotal, $breakdown->subtotal);
        }
    }

    /**
     * One order line, snapshotting its tax category and resolved VAT rate. The
     * rate comes from the priced breakdown line at the same position. The
     * alignment is safe because `Cart::items()` orders deterministically (by id)
     * and the breakdown is built from that same ordered relation — see G6. The
     * tax *amount* is not stored per line; it lives in the order's per-bracket
     * `tax_summary`.
     *
     * @return array{cartable: mixed, attributes: array<string, mixed>}
     */
    private function buildLineDraft(mixed $item, PriceBreakdown $breakdown, int $index): array
    {
        $cartable = $item->cartable;
        $line = $this->resolveLineData($cartable, $breakdown->currency->value, $item->unit_price);
        $line['quantity'] = $item->quantity;
        $line['line_total'] = $line['unit_price'] * $item->quantity;
        $line['tax_category'] = $this->resolveLineTaxCategory($cartable);
        $line['tax_rate_bps'] = $breakdown->lines[$index]->taxRateBps ?? null;
        $line['tax_amount'] = null;

        return ['cartable' => $cartable, 'attributes' => $line];
    }

    private function resolveLineTaxCategory(mixed $cartable): string
    {
        if ($cartable instanceof HasTaxCategory) {
            return $cartable->taxCategory()->value;
        }

        return TaxCategory::PhysicalGoods->value;
    }

    /**
     * @param  array{cartable: mixed, attributes: array<string, mixed>}  $draft
     */
    private function persistLine(Order $order, array $draft): void
    {
        $orderLine = new (Commerce::orderLine())($draft['attributes']);
        $orderLine->order()->associate($order);

        if ($draft['cartable'] instanceof Model) {
            $orderLine->orderable()->associate($draft['cartable']);
        }

        $orderLine->save();
    }

    /**
     * @return array{description: string, sku: string|null, currency: string, unit_price: int, is_pre_order?: bool, expected_ship_date?: string|null}
     */
    private function resolveLineData(mixed $cartable, string $currencyCode, ?int $snapshotUnitPrice): array
    {
        if ($cartable instanceof HasOrders) {
            return $cartable->toOrderLineData($currencyCode);
        }

        return [
            'description' => '(missing item)',
            'sku' => null,
            'currency' => $currencyCode,
            'unit_price' => $snapshotUnitPrice ?? 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $addressData
     */
    private function attachAddress(Order $order, array $addressData, AddressType $type): void
    {
        /** @var Address $address */
        $address = $order->addresses()->make($addressData);
        $address->type = $type;
        $address->save();
    }
}
