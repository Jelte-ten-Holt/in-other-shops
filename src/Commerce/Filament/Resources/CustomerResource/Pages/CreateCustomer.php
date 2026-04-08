<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\Resources\CustomerResource\Pages;

use InOtherShops\Commerce\Customer\Actions\CreateCustomer as CreateCustomerAction;
use InOtherShops\Commerce\Filament\Resources\CustomerResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return (new CreateCustomerAction)(
            name: $data['name'],
            email: $data['email'],
            phone: $data['phone'] ?? null,
            customerGroupId: isset($data['customer_group_id']) ? (int) $data['customer_group_id'] : null,
        );
    }
}
