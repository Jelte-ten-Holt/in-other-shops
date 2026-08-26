<?php

declare(strict_types=1);

namespace InOtherShops\Tracking\FlowChains\Steps;

use Illuminate\Database\Eloquent\Relations\Relation;
use InOtherShops\Commerce\Cart\FlowChains\AddToCartPayload;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\FlowChain\AbstractFlowStep;
use InOtherShops\FlowChain\Contracts\FlowPayload;
use InOtherShops\Tracking\Tracking;

/**
 * Writes a CartItemAttribution row for the just-created/incremented cart_item
 * when the payload's metadata carries a `source` key.
 *
 * # Metadata shape
 *
 * The package's cart HTTP layer accepts a free-form `metadata` array on
 * cart-add requests. For attribution the convention is:
 *
 *     { "source": { "type": "<morph alias>", "id": <int> } }
 *
 * Valid `type` values are whatever the CONSUMER has in its morph map —
 * content/category/tag in in-other-worlds, category/tag in bianka. The package
 * takes no view on which sources are meaningful; it only refuses to write a
 * row it could never join back (see below).
 *
 * # Silent skip, never throw
 *
 * Anything malformed — no source, a non-array source, an unknown alias, a
 * non-positive or non-integer id — writes no row and lets the add proceed.
 * Attribution is supplementary; a typo'd source must never cost a shopper
 * their add-to-cart. The unknown-alias check specifically keeps un-joinable
 * rows out of the table, which is the failure that would be invisible until
 * someone tried to report on it.
 *
 * # First-source-wins
 *
 * `cart_item_attributions` is unique on cart_item_id, so a second add of the
 * same item (a quantity bump) leaves the original source in place — a re-add
 * is a quantity change, not a new conversion. The `exists()` check makes that
 * a clean skip rather than a caught constraint violation; two truly concurrent
 * adds of the same item can still race to the unique key, which is accepted:
 * the loser's exception would roll back a cart-add, but the window is a single
 * request pair on the same cart and the recorded source is correct either way.
 *
 * @reads cartItem, metadata
 */
final class RecordCartItemAttribution extends AbstractFlowStep
{
    public function handle(FlowPayload $payload): void
    {
        assert($payload instanceof AddToCartPayload);

        // No cart item means an upstream step did not run. Skip rather than
        // assert: this step is supplementary, and a consumer reordering their
        // published chain should not get a fatal from the analytics step.
        if ($payload->cartItem === null) {
            return;
        }

        $source = $this->extractSource($payload->metadata);

        if ($source === null) {
            return;
        }

        $model = Tracking::cartItemAttribution();

        $exists = $model::query()
            ->where('cart_item_id', $payload->cartItem->id)
            ->exists();

        if ($exists) {
            return;
        }

        $model::query()->create([
            'cart_item_id' => $payload->cartItem->id,
            'source_type' => $source['type'],
            'source_id' => $source['id'],
            'created_at' => now(),
        ]);
    }

    public static function expectedInputs(): array
    {
        return [
            'cartItem' => CartItem::class,
            'metadata' => 'array',
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{type: string, id: int}|null
     */
    private function extractSource(array $metadata): ?array
    {
        $source = $metadata['source'] ?? null;

        if (! is_array($source)) {
            return null;
        }

        $type = $source['type'] ?? null;
        $id = $source['id'] ?? null;

        if (! is_string($type) || $type === '') {
            return null;
        }

        // Morph-map allowlist: silently drop unknown aliases so a typo doesn't
        // litter the table with rows nothing can join back to a model.
        if (Relation::getMorphedModel($type) === null) {
            return null;
        }

        // Strictly int: a JSON body carrying "12" is a client bug, and coercing
        // it would hide the bug behind rows that look correct.
        if (! is_int($id) || $id <= 0) {
            return null;
        }

        return ['type' => $type, 'id' => $id];
    }
}
