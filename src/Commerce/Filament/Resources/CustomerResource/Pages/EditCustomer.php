<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\Resources\CustomerResource\Pages;

use InOtherShops\Commerce\Customer\Actions\UpdateCustomer as UpdateCustomerAction;
use InOtherShops\Commerce\Filament\Resources\CustomerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        (new UpdateCustomerAction)(
            customer: $record,
            name: $data['name'],
            email: $data['email'],
            phone: $data['phone'] ?? null,
            customerGroupId: isset($data['customer_group_id']) ? (int) $data['customer_group_id'] : null,
        );

        return $record;
    }
}
