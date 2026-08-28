<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Tracking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Cart\FlowChains\AddToCartPayload;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\TestCase;
use InOtherShops\Tracking\FlowChains\Steps\RecordCartItemAttribution;
use InOtherShops\Tracking\Models\CartItemAttribution;
use PHPUnit\Framework\Attributes\Test;

/**
 * The step's whole job is to be unfailingly quiet: every malformed input must
 * produce no row and no exception, because a bad `source` must never cost a
 * shopper their add-to-cart. Each negative case below is therefore asserting
 * two things at once — no row, and no throw (the test would error on a throw).
 */
final class RecordCartItemAttributionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_writes_the_source_from_the_payload_metadata(): void
    {
        $source = TestCartable::factory()->create();
        $payload = $this->payloadWithMetadata([
            'source' => ['type' => 'test_cartable', 'id' => (int) $source->getKey()],
        ]);

        app(RecordCartItemAttribution::class)->handle($payload);

        $row = CartItemAttribution::query()->sole();
        $this->assertSame($payload->cartItem->id, (int) $row->cart_item_id);
        $this->assertSame('test_cartable', $row->source_type);
        $this->assertSame((int) $source->getKey(), (int) $row->source_id);
        $this->assertNotNull($row->created_at);
    }

    #[Test]
    public function the_source_relation_resolves_back_to_the_model(): void
    {
        // The point of the morph-alias allowlist: a written row must be
        // joinable. A row nothing can resolve is invisible until reporting.
        $source = TestCartable::factory()->create();
        $payload = $this->payloadWithMetadata([
            'source' => ['type' => 'test_cartable', 'id' => (int) $source->getKey()],
        ]);

        app(RecordCartItemAttribution::class)->handle($payload);

        $this->assertTrue($source->is(CartItemAttribution::query()->sole()->source));
    }

    #[Test]
    public function metadata_without_a_source_writes_nothing(): void
    {
        app(RecordCartItemAttribution::class)->handle($this->payloadWithMetadata([]));

        $this->assertSame(0, CartItemAttribution::query()->count());
    }

    #[Test]
    public function an_unknown_morph_alias_is_skipped(): void
    {
        app(RecordCartItemAttribution::class)->handle($this->payloadWithMetadata([
            'source' => ['type' => 'not_a_registered_alias', 'id' => 1],
        ]));

        $this->assertSame(0, CartItemAttribution::query()->count());
    }

    #[Test]
    public function a_malformed_source_is_skipped(): void
    {
        foreach ([
            'scalar source' => ['source' => 'test_cartable'],
            'missing type' => ['source' => ['id' => 1]],
            'empty type' => ['source' => ['type' => '', 'id' => 1]],
            'missing id' => ['source' => ['type' => 'test_cartable']],
            'zero id' => ['source' => ['type' => 'test_cartable', 'id' => 0]],
            'negative id' => ['source' => ['type' => 'test_cartable', 'id' => -3]],
        ] as $label => $metadata) {
            app(RecordCartItemAttribution::class)->handle($this->payloadWithMetadata($metadata));

            $this->assertSame(0, CartItemAttribution::query()->count(), "Wrote a row for: {$label}");
        }
    }

    #[Test]
    public function a_string_id_is_skipped_rather_than_coerced(): void
    {
        // A JSON body carrying "12" is a client bug. Coercing it would hide the
        // bug behind rows that look correct.
        app(RecordCartItemAttribution::class)->handle($this->payloadWithMetadata([
            'source' => ['type' => 'test_cartable', 'id' => '12'],
        ]));

        $this->assertSame(0, CartItemAttribution::query()->count());
    }

    #[Test]
    public function a_second_add_does_not_overwrite_the_first_source(): void
    {
        $first = TestCartable::factory()->create();
        $second = TestCartable::factory()->create();

        $payload = $this->payloadWithMetadata([
            'source' => ['type' => 'test_cartable', 'id' => (int) $first->getKey()],
        ]);
        app(RecordCartItemAttribution::class)->handle($payload);

        // Same cart item, different source — a quantity bump, not a new
        // conversion. First source wins.
        app(RecordCartItemAttribution::class)->handle($this->payloadWithMetadata(
            ['source' => ['type' => 'test_cartable', 'id' => (int) $second->getKey()]],
            reuse: $payload->cartItem,
        ));

        $row = CartItemAttribution::query()->sole();
        $this->assertSame((int) $first->getKey(), (int) $row->source_id);
    }

    #[Test]
    public function a_payload_without_a_cart_item_is_skipped(): void
    {
        $cart = Cart::factory()->create();
        $cartable = TestCartable::factory()->create(['unit_price' => 1000]);
        $payload = new AddToCartPayload(
            cart: $cart,
            cartable: $cartable,
            quantity: 1,
            metadata: ['source' => ['type' => 'test_cartable', 'id' => (int) $cartable->getKey()]],
        );

        app(RecordCartItemAttribution::class)->handle($payload);

        $this->assertSame(0, CartItemAttribution::query()->count());
    }

    /**
     * A payload standing where the step runs: after FindOrCreateCartItemStep,
     * so `cartItem` is populated. `metadata` is readonly on the payload, so a
     * second add against the SAME cart item builds a second payload rather than
     * mutating this one.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function payloadWithMetadata(array $metadata, ?CartItem $reuse = null): AddToCartPayload
    {
        if ($reuse !== null) {
            $payload = new AddToCartPayload(
                cart: $reuse->cart,
                cartable: $reuse->cartable,
                quantity: 1,
                metadata: $metadata,
            );
            $payload->cartItem = $reuse;

            return $payload;
        }

        $cartable = TestCartable::factory()->create(['unit_price' => 1000]);
        $cart = Cart::factory()->create();

        $cartItem = $cart->items()->create([
            'cartable_type' => $cartable->getMorphClass(),
            'cartable_id' => $cartable->getKey(),
            'quantity' => 1,
            'unit_price' => 1000,
            'currency' => Currency::EUR,
        ]);

        $payload = new AddToCartPayload(
            cart: $cart,
            cartable: $cartable,
            quantity: 1,
            metadata: $metadata,
        );
        $payload->cartItem = $cartItem;

        return $payload;
    }
}
