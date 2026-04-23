<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Actions;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Customer\Models\Customer;
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
    ): Order {
        $order = DB::transaction(fn (): Order => $this->build($cart, $breakdown, $billingAddress, $shippingAddress, $customer, $guestEmail, $taxSnapshot, $shippingSnapshot));

        OrderCreated::dispatch($order);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $billingAddress
     * @param  array<string, mixed>|null  $shippingAddress
     */
    private function build(Cart $cart, PriceBreakdown $breakdown, array $billingAddress, ?array $shippingAddress, ?Customer $customer, ?string $guestEmail, ?TaxSnapshot $taxSnapshot, ?ShippingSnapshot $shippingSnapshot): Order
    {
        $this->commitVoucherUsage($breakdown);

        $order = $this->createOrderRecord($breakdown, $customer, $guestEmail, $taxSnapshot, $shippingSnapshot);

        $this->attachLines($order, $cart, $breakdown);
        $this->attachAddress($order, $billingAddress, $shippingAddress === null ? AddressType::ShippingAndBilling : AddressType::Billing);

        if ($shippingAddress !== null) {
            $this->attachAddress($order, $shippingAddress, AddressType::Shipping);
        }

        return $order;
    }

    private function commitVoucherUsage(PriceBreakdown $breakdown): void
    {
        if ($breakdown->voucherCode === null) {
            return;
        }

        ($this->applyVoucher)(
            subtotal: $breakdown->subtotal,
            code: $breakdown->voucherCode,
            currency: $breakdown->currency,
        );
    }

    private function createOrderRecord(PriceBreakdown $breakdown, ?Customer $customer, ?string $guestEmail, ?TaxSnapshot $taxSnapshot, ?ShippingSnapshot $shippingSnapshot): Order
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
            'discount' => $breakdown->discount,
            'total' => $breakdown->total,
            'shipping_method_identifier' => $shippingSnapshot?->methodIdentifier,
            'shipping_cost' => $shippingSnapshot?->cost ?? 0,
            'shipping_cost_currency' => $shippingSnapshot?->currency,
            'customer_id' => $customer?->getKey(),
            'email' => $guestEmail,
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

    private function attachLines(Order $order, Cart $cart, PriceBreakdown $breakdown): void
    {
        $cart->loadMissing('items.cartable');

        foreach ($cart->items as $item) {
            $cartable = $item->cartable;

            $line = $this->resolveLineData($cartable, $breakdown->currency->value, $item->quantity, $item->unit_price);

            $orderLine = new (Commerce::orderLine())($line + ['quantity' => $item->quantity, 'line_total' => $line['unit_price'] * $item->quantity]);
            $orderLine->order()->associate($order);

            if ($cartable instanceof Model) {
                $orderLine->orderable()->associate($cartable);
            }

            $orderLine->save();
        }
    }

    /**
     * @return array{description: string, sku: string|null, currency: string, unit_price: int, is_pre_order?: bool}
     */
    private function resolveLineData(mixed $cartable, string $currencyCode, int $quantity, ?int $snapshotUnitPrice): array
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
