{{--
    Modal body for InventorySchema::viewMovementsAction().

    The table itself is the `inventory-stock-movements-table` Livewire
    component (registered in InventoryServiceProvider) — this view exists only
    to mount it with the stockable the action was opened from.
--}}
@livewire('inventory-stock-movements-table', [
    'stockableType' => $stockableType,
    'stockableId' => $stockableId,
])
