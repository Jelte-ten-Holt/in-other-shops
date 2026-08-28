<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Actions;

use Illuminate\Support\Carbon;
use InOtherShops\Shipping\Enums\ShipmentStatus;
use InOtherShops\Shipping\Events\ShipmentDispatched;
use InOtherShops\Shipping\Models\Shipment;

final class DispatchShipment
{
    public function __construct(
        private readonly UpdateShipmentStatus $updateStatus,
    ) {}

    /**
     * Mark a shipment as handed to the carrier.
     *
     * TRACKING IS OPTIONAL, and that is load-bearing. Untracked post is a real
     * service a shop sells — the cheaper of the two methods for a small parcel,
     * and the majority case for some consumers. Requiring a tracking number to
     * reach `InTransit` made the package state something untrue about those
     * shops, and left their shipments stuck at `Ready` forever: every feature
     * downstream of dispatch (a "your order shipped" mail, a review invitation
     * keyed on `shipped_at`) then silently never fires, with the suite green.
     *
     * So `shipped_at` is stamped and `ShipmentDispatched` is dispatched
     * REGARDLESS of tracking. A consumer that wants to insist on a number does
     * it in its own admin form, not by being unable to express the alternative.
     *
     * When `$trackingUrl` is null, the carrier's `tracking_url_template` from
     * `config('shipping.carriers')` is applied if available; carriers without a
     * template (or with one-off URLs that can't be templated) require an
     * explicit `$trackingUrl`.
     */
    public function __invoke(
        Shipment $shipment,
        ?string $trackingNumber = null,
        ?string $carrier = null,
        ?string $trackingUrl = null,
    ): Shipment {
        ($this->updateStatus)($shipment, ShipmentStatus::InTransit, [
            'carrier' => $carrier,
            'tracking_number' => $trackingNumber,
            'tracking_url' => $trackingUrl ?? $this->resolveTrackingUrl($carrier, $trackingNumber),
            'shipped_at' => Carbon::now(),
        ]);

        ShipmentDispatched::dispatch($shipment);

        return $shipment;
    }

    /**
     * No carrier or no number, no derived URL — stated explicitly rather than
     * left to fall out of a config miss on `shipping.carriers..*`. A blank
     * string counts as absent: with the admin form's `required()` gone, an
     * untouched Filament text input arrives as `''`, and callsites that forget
     * to normalize it must not end up templating an empty number into a URL
     * that resolves to a carrier's "not found" page.
     */
    private function resolveTrackingUrl(?string $carrier, ?string $trackingNumber): ?string
    {
        if ($carrier === null || $carrier === '' || $trackingNumber === null || $trackingNumber === '') {
            return null;
        }

        $template = config("shipping.carriers.{$carrier}.tracking_url_template");

        if (! is_string($template) || $template === '') {
            return null;
        }

        return str_replace('{tracking_number}', $trackingNumber, $template);
    }
}
