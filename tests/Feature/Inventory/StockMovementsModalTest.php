<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Inventory;

use Filament\Actions\Action;
use InOtherShops\Inventory\Filament\InventorySchema;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\TestCase;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The "Movement history" header action opened a modal whose body view did not
 * exist anywhere — the package shipped no views and registered no view
 * namespace, so every consumer's button threw "View [...] not found" on click.
 * Surfaced as in-other-worlds Cowork finding F-13 (2026-05-28).
 */
final class StockMovementsModalTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_modal_body_view_the_action_points_at_exists(): void
    {
        $this->assertTrue(
            view()->exists('shops-inventory::stock-movements-modal'),
            'The domain view namespace is registered and ships the modal body.',
        );
    }

    #[Test]
    public function the_movement_history_action_mounts_the_table_for_its_record(): void
    {
        $stockable = TestStockable::query()->create(['name' => 'Widget']);

        $action = $this->movementHistoryAction();
        $action->record($stockable);

        $content = $action->getModalContent();

        $this->assertInstanceOf(View::class, $content);
        $this->assertSame('shops-inventory::stock-movements-modal', $content->name());
        $this->assertSame([
            'stockableType' => $stockable->getMorphClass(),
            'stockableId' => $stockable->getKey(),
        ], $content->getData());
    }

    /**
     * The view mounts the Livewire component by its registered alias. Livewire
     * itself is not booted in the package test env (it arrives with Filament in
     * a consumer), so this pins the alias as text rather than rendering it —
     * enough to catch the two halves drifting apart.
     */
    #[Test]
    public function the_modal_body_mounts_the_registered_livewire_component(): void
    {
        $this->assertStringContainsString(
            "@livewire('inventory-stock-movements-table'",
            file_get_contents(
                (string) view('shops-inventory::stock-movements-modal', [
                    'stockableType' => 'test_stockable',
                    'stockableId' => 1,
                ])->getPath(),
            ),
        );
    }

    private function movementHistoryAction(): Action
    {
        $actions = InventorySchema::stockSection()->getHeaderActions();

        $this->assertCount(1, $actions);

        return $actions[0];
    }
}
