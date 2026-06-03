<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Filament\Resources\PurchaseOrderResource\Pages;

use InOtherShops\Purchasing\Actions\CreatePurchaseOrder as CreatePurchaseOrderAction;
use InOtherShops\Purchasing\Filament\Resources\PurchaseOrderResource;
use InOtherShops\Purchasing\Purchasing;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

final class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $supplier = Purchasing::supplier()::query()->findOrFail($data['supplier_id']);

        $lines = array_map(
            fn (array $line): array => [
                'purchasable' => self::resolvePurchasable($line),
                'description' => $line['description'] ?? null,
                'sku' => $line['sku'] ?? null,
                'quantity_ordered' => (int) ($line['quantity_ordered'] ?? 1),
                'unit_cost' => (int) ($line['unit_cost'] ?? 0),
                'input_vat' => isset($line['input_vat']) && $line['input_vat'] !== null && $line['input_vat'] !== ''
                    ? (int) $line['input_vat']
                    : null,
            ],
            array_values($data['lines'] ?? []),
        );

        return (new CreatePurchaseOrderAction)(
            supplier: $supplier,
            lines: $lines,
            expectedDeliveryAt: empty($data['expected_delivery_at'])
                ? null
                : new \DateTimeImmutable((string) $data['expected_delivery_at']),
            shippingCost: (int) ($data['shipping_cost'] ?? 0),
            customsCost: (int) ($data['customs_cost'] ?? 0),
            notes: $data['notes'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private static function resolvePurchasable(array $line): ?Model
    {
        $type = $line['purchasable_type'] ?? null;
        $id = $line['purchasable_id'] ?? null;

        if (empty($type) || empty($id)) {
            return null;
        }

        $class = Relation::getMorphedModel((string) $type) ?? (class_exists((string) $type) ? $type : null);

        if ($class === null) {
            return null;
        }

        return $class::query()->find($id);
    }
}
