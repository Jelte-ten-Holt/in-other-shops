<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\Resources\CustomerResource\Pages;

use InOtherShops\Commerce\Customer\Actions\UpdateCustomer as UpdateCustomerAction;
use InOtherShops\Commerce\Filament\Resources\CustomerResource;
use InOtherShops\Support\Filament\PackageEditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditCustomer extends PackageEditRecord
{
    protected static string $resource = CustomerResource::class;


    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        (new UpdateCustomerAction)(
            customer: $record,
            name: $data['name'],
            email: $data['email'],
            phone: $data['phone'] ?? null,
            // The form no longer carries a group select (customer groups are
            // backend-only until pricing rules exist) — preserve the stored
            // assignment instead of nulling it on every unrelated edit.
            customerGroupId: isset($data['customer_group_id'])
                ? (int) $data['customer_group_id']
                : ($record->customer_group_id === null ? null : (int) $record->customer_group_id),
        );

        return $record;
    }
}
