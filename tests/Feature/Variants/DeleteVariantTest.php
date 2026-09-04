<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Variants;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Exceptions\CartReferencesCartableException;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Models\Media;
use InOtherShops\Variants\Actions\DeleteVariant;
use InOtherShops\Variants\Events\VariantDeleted;
use InOtherShops\Variants\Models\Variant;
use InOtherShops\Tests\Stubs\TestVariantable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DeleteVariantTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_deletes_the_variant_and_its_owned_rows(): void
    {
        $variant = $this->makeVariant();
        $variant->prices()->create(['amount' => 1000, 'currency' => 'EUR', 'minimum_quantity' => 1]);
        StockItem::factory()->for($variant, 'stockable')->withLevel(5)->create();

        app(DeleteVariant::class)($variant);

        $this->assertModelMissing($variant);
        $this->assertDatabaseMissing('prices', ['priceable_type' => 'variant', 'priceable_id' => $variant->id]);
        $this->assertDatabaseMissing('stock_items', ['stockable_type' => 'variant', 'stockable_id' => $variant->id]);
    }

    #[Test]
    public function it_dispatches_variant_deleted(): void
    {
        Event::fake([VariantDeleted::class]);
        $variant = $this->makeVariant();

        app(DeleteVariant::class)($variant);

        Event::assertDispatched(VariantDeleted::class, fn (VariantDeleted $event): bool => $event->variant->id === $variant->id);
    }

    #[Test]
    public function it_is_blocked_when_a_live_cart_references_the_variant(): void
    {
        $variant = $this->makeVariant();
        $cart = Cart::factory()->create(['expires_at' => null]);
        $variant->cartItems()->create(['cart_id' => $cart->id, 'quantity' => 1, 'unit_price' => 1000, 'currency' => 'EUR']);

        try {
            app(DeleteVariant::class)($variant);
            $this->fail('Expected deletion to be blocked.');
        } catch (CartReferencesCartableException) {
            $this->assertModelExists($variant);
        }
    }

    /**
     * Detaching only dropped the pivot: every variant deletion left its media
     * rows — and the files behind them — on disk with nothing referencing them
     * (F5, the third orphan source in the media-pipeline brief).
     */
    #[Test]
    public function it_deletes_media_rows_the_variant_was_the_last_to_reference(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/variant.jpeg', 'x');

        $variant = $this->makeVariant();
        $media = $this->uploadMedia('media/variant.jpeg');
        $variant->media()->attach($media->getKey(), ['collection' => 'images', 'position' => 0]);

        app(DeleteVariant::class)($variant);

        $this->assertModelMissing($media);
        $this->assertFalse(Storage::disk('public')->exists('media/variant.jpeg'));
    }

    #[Test]
    public function it_keeps_a_media_row_another_variant_still_references(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/shared.jpeg', 'x');

        $variantable = TestVariantable::factory()->create();
        $doomed = Variant::factory()->for($variantable, 'variantable')->create();
        $sibling = Variant::factory()->for($variantable, 'variantable')->create();
        $media = $this->uploadMedia('media/shared.jpeg');

        $doomed->media()->attach($media->getKey(), ['collection' => 'images', 'position' => 0]);
        $sibling->media()->attach($media->getKey(), ['collection' => 'images', 'position' => 0]);

        app(DeleteVariant::class)($doomed);

        $this->assertModelExists($media);
        $this->assertTrue(Storage::disk('public')->exists('media/shared.jpeg'));
        $this->assertSame(1, $sibling->media()->count());
    }

    private function uploadMedia(string $path): Media
    {
        return Media::create([
            'type' => MediaType::Upload,
            'disk' => 'public',
            'path' => $path,
            'filename' => basename($path),
            'mime_type' => 'image/jpeg',
            'size' => 1,
        ]);
    }

    private function makeVariant(): Variant
    {
        return Variant::factory()
            ->for(TestVariantable::factory()->create(), 'variantable')
            ->create();
    }
}
