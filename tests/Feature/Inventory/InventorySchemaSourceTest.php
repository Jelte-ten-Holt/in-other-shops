<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Filament\InventorySchema;
use InOtherShops\Inventory\Models\StockMovement;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pins that the admin Filament path stamps `source = 'dashboard'` on every
 * stock movement it writes. Pairs with the agent tool (already stamps
 * `'agent'`) and the project-side checkout step (`'checkout'`) so the
 * StockMovement ledger can be filtered by where each adjustment came from.
 *
 * Without source set, `config('inventory.sources')` is documented but its
 * `dashboard` / `checkout` / `import` keys go unused — admin and checkout
 * adjustments are indistinguishable in audits.
 */
final class InventorySchemaSourceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_form_save_stamps_source_dashboard_on_the_movement(): void
    {
        $stockable = TestStockable::factory()->create();

        InventorySchema::saveFormData($stockable, [
            '_stock' => [
                'low_stock_threshold' => null,
                'adjustment_quantity' => 5,
                'adjustment_reason' => StockMovementReason::Restock->value,
                'adjustment_description' => null,
            ],
        ]);

        $movement = StockMovement::query()->first();
        $this->assertNotNull($movement, 'A non-zero adjustment must produce a movement row.');
        $this->assertSame('dashboard', $movement->source,
            'Filament admin adjustments must stamp source=dashboard so the audit can tell them apart from checkout/agent/import paths.');
    }

    #[Test]
    public function a_zero_adjustment_writes_no_movement_at_all(): void
    {
        // The processAdjustment short-circuit; documented behavior of the
        // form is that an empty/zero adjustment is a no-op even if reason
        // is set. Without this, every save would log a phantom 0-delta.
        $stockable = TestStockable::factory()->create();

        InventorySchema::saveFormData($stockable, [
            '_stock' => [
                'low_stock_threshold' => 5,
                'adjustment_quantity' => 0,
                'adjustment_reason' => StockMovementReason::Restock->value,
                'adjustment_description' => null,
            ],
        ]);

        $this->assertSame(0, StockMovement::query()->count());
    }
}
