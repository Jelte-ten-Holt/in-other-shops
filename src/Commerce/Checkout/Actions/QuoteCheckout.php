<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Checkout\Actions;

use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Checkout\DTOs\CheckoutQuote;
use InOtherShops\Commerce\Checkout\DTOs\ShippingMethodQuote;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Actions\CalculateVoucherDiscount;
use InOtherShops\Pricing\Enums\TaxMode;
use InOtherShops\Pricing\Exceptions\PricingException;
use InOtherShops\Pricing\PricingConfig;
use InOtherShops\Shipping\Actions\CalculateShippingCost;
use InOtherShops\Shipping\Actions\ListAvailableShippingMethods;
use InOtherShops\Shipping\Actions\ResolveShippingZoneForCountry;
use InOtherShops\Shipping\DTOs\ShippingMethod;
use InOtherShops\Shipping\DTOs\ShippingZone;
use RuntimeException;

/**
 * Quotes the checkout form: subtotal, voucher discount, and the order total
 * per available shipping method, all from one place — so the total the form
 * shows and the total the consumer's checkout chain later charges share one
 * arithmetic path instead of each app hand-mirroring
 * `subtotal − discount + shipping`.
 *
 * PRICE SOURCE — the cart snapshot, deliberately. The quote reads the cart's
 * stored `unit_price` (Cart::subtotalCents()), the same source the displayed
 * cart lines and the free-shipping threshold use, so a quote can never
 * disagree with the lines on the same page. Freshness is RepriceCart's job,
 * on its documented cadence (render / voucher apply / submit-with-bounce) —
 * after a reprice, snapshot and live agree everywhere at once. Quoting live
 * prices here instead would desync the quote from the displayed lines and
 * from the threshold the charge path reads.
 *
 * One quote term per cart item, in Cart::items() order, never filtered — a
 * line whose cartable has since been deleted still counts at its snapshot
 * price. Rejecting unpriceable lines is the consumer's ValidateCart-style
 * step's job; silently skipping them here would quote a total the chain then
 * refuses to charge (and, downstream, would shift breakdown lines off their
 * cart items — the G6 positional-VAT invariant).
 *
 * TAX — none resolved, and exact anyway: under the inclusive tax mode VAT is
 * extracted from the gross, never added, so it cannot change any quoted
 * total. Exclusive (B2B) pricing throws here exactly as it does in
 * CalculateTotal, so implementing it forcibly revisits quoting too. Whether
 * to *display* an included-VAT line before checkout stays a consumer choice.
 *
 * VOUCHER — validated against the same snapshot subtotal via the shared
 * Voucher::validateForUse() guard. The render path calls with
 * `$throwOnInvalidVoucher = false`: a code that stopped applying is dropped
 * (`droppedVoucherCode`), because mid-checkout the honest move is to quote
 * the real price, not block the page. The apply endpoint calls with `true`
 * and turns the exception into a shopper-facing message. One subtotal, one
 * guard, both paths — an apply/render flip-flop around the voucher minimum
 * cannot happen.
 */
final class QuoteCheckout
{
    public function __construct(
        private readonly CalculateVoucherDiscount $calculateVoucherDiscount,
        private readonly ResolveShippingZoneForCountry $resolveZone,
        private readonly ListAvailableShippingMethods $listMethods,
        private readonly CalculateShippingCost $calculateShippingCost,
    ) {}

    public function __invoke(
        Cart $cart,
        ?string $voucherCode = null,
        ?string $countryCode = null,
        bool $throwOnInvalidVoucher = false,
    ): CheckoutQuote {
        $this->assertInclusiveTaxMode();

        $cart->loadMissing('items.cartable');

        $currency = $cart->effectiveCurrency();
        $subtotal = $cart->subtotalCents();

        [$appliedCode, $discount, $droppedCode] = $this->resolveVoucher(
            $voucherCode, $subtotal, $currency, $throwOnInvalidVoucher,
        );

        $requiresShipping = $cart->requiresShipping();
        $zone = $requiresShipping && $countryCode !== null
            ? ($this->resolveZone)($countryCode)
            : null;

        return new CheckoutQuote(
            subtotal: $subtotal,
            discount: $discount,
            totalWithoutShipping: $subtotal - $discount,
            currency: $currency,
            voucherCode: $appliedCode,
            droppedVoucherCode: $droppedCode,
            requiresShipping: $requiresShipping,
            canShip: ! $requiresShipping || $zone !== null,
            methodQuotes: $zone !== null
                ? $this->quoteMethods($zone, $subtotal, $discount)
                : [],
        );
    }

    /**
     * The voucher, dry-run against the snapshot subtotal. Returns
     * [appliedCode, discount, droppedCode]. Pure — usage is only ever
     * recorded by ApplyVoucher inside CreateOrder.
     *
     * @return array{?string, int, ?string}
     */
    private function resolveVoucher(
        ?string $voucherCode,
        int $subtotal,
        Currency $currency,
        bool $throwOnInvalidVoucher,
    ): array {
        if ($voucherCode === null) {
            return [null, 0, null];
        }

        try {
            $discount = ($this->calculateVoucherDiscount)($subtotal, $voucherCode, $currency);
        } catch (PricingException $exception) {
            if ($throwOnInvalidVoucher) {
                throw $exception;
            }

            // Not found, expired, spent, under the minimum, wrong currency —
            // all mean "this code does not apply to this cart", and none of
            // them should hold up the checkout page.
            return [null, 0, $voucherCode];
        }

        return [$voucherCode, $discount, null];
    }

    /**
     * The order total per shipping method the zone offers. The free-shipping
     * threshold is judged against the PRE-discount subtotal (a voucher comes
     * off the goods, never off the postage qualification), matching the
     * consumer chains' SelectShippingMethod steps — the two sides of the
     * quote/charge pair must read the same figure.
     *
     * @return list<ShippingMethodQuote>
     */
    private function quoteMethods(ShippingZone $zone, int $subtotal, int $discount): array
    {
        return array_map(
            function (ShippingMethod $method) use ($zone, $subtotal, $discount): ShippingMethodQuote {
                $cost = ($this->calculateShippingCost)($method, $zone, subtotalCents: $subtotal);

                return new ShippingMethodQuote(
                    identifier: $method->identifier,
                    name: $method->name,
                    cost: $cost,
                    // The PriceBreakdown identity, verbatim: the voucher comes
                    // off the goods, never the postage.
                    total: $subtotal - $discount + $cost,
                );
            },
            ($this->listMethods)($zone),
        );
    }

    private function assertInclusiveTaxMode(): void
    {
        if (PricingConfig::defaultTaxMode() === TaxMode::Exclusive) {
            throw new RuntimeException(
                'Exclusive (tax-exclusive) pricing is not implemented yet — B2B seam. '
                .'A tax-exclusive quote must resolve tax rates; revisit QuoteCheckout alongside CalculateTotal.',
            );
        }
    }
}
