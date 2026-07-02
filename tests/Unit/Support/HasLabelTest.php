<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Unit\Support;

use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Location\Enums\AddressType;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Purchasing\Enums\PurchaseOrderStatus;
use InOtherShops\Shipping\Enums\ShipmentStatus;
use InOtherShops\Support\Transitionable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * T-E1 — the shared HasLabel trait derives a sentence-case admin label from the
 * enum's backing value (`partially_received` → "Partially received"). The lone
 * outlier `PaymentStatus::PartiallyRefunded` is corrected from Title Case to
 * sentence case here; `AddressType` keeps one ampersand override.
 */
final class HasLabelTest extends TestCase
{
    #[Test]
    public function single_word_values_are_upper_cased(): void
    {
        $this->assertSame('Draft', PurchaseOrderStatus::Draft->label());
        $this->assertSame('Delivered', ShipmentStatus::Delivered->label());
    }

    #[Test]
    public function multi_word_values_become_sentence_case_not_title_case(): void
    {
        $this->assertSame('In transit', ShipmentStatus::InTransit->label());
        $this->assertSame('Returned to sender', ShipmentStatus::ReturnedToSender->label());
        $this->assertSame('Partially received', PurchaseOrderStatus::PartiallyReceived->label());
    }

    #[Test]
    public function partially_refunded_is_corrected_to_sentence_case(): void
    {
        // Regression anchor: this was the lone Title-Case outlier
        // ("Partially Refunded"); the shared trait makes it sentence case.
        $this->assertSame('Partially refunded', PaymentStatus::PartiallyRefunded->label());
    }

    #[Test]
    public function address_type_keeps_its_ampersand_override_but_defaults_the_rest(): void
    {
        $this->assertSame('Shipping & Billing', AddressType::ShippingAndBilling->label());
        // The override delegates every other case to the sentence-case default.
        $this->assertSame('Shipping', AddressType::Shipping->label());
        $this->assertSame('Billing', AddressType::Billing->label());
    }

    #[Test]
    public function the_trait_satisfies_the_transitionable_label_contract(): void
    {
        $status = OrderStatus::Confirmed;

        $this->assertInstanceOf(Transitionable::class, $status);
        $this->assertSame('Confirmed', $status->label());
    }
}
