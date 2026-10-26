<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Filament\Resources\PurchaseOrderResource\Pages;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use InOtherShops\Purchasing\Exceptions\SupplierInvoiceException;
use InOtherShops\Purchasing\Filament\Resources\PurchaseOrderResource;
use InOtherShops\Support\Filament\PackageEditRecord;

final class EditPurchaseOrder extends PackageEditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    /**
     * The form checks the supplier invoice field by field; anything it lets
     * through that the model still refuses comes back as a notification that
     * says why, not an error page. Nothing is written.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (SupplierInvoiceException $e) {
            Notification::make()
                ->title(__('shops-purchasing::purchaseorder.notifications.invoice_refused'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt(shouldRollbackDatabaseTransaction: true);

            return $record;
        }
    }
}
