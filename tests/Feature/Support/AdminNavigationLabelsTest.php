<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Support;

use InOtherShops\Commerce\Filament\RelationManagers\CustomerOrdersRelationManager;
use InOtherShops\Commerce\Filament\RelationManagers\OrderAddressesRelationManager;
use InOtherShops\Commerce\Filament\RelationManagers\OrderLinesRelationManager;
use InOtherShops\Commerce\Filament\Resources\CustomerGroupResource;
use InOtherShops\Commerce\Filament\Resources\CustomerResource;
use InOtherShops\Commerce\Filament\Resources\OrderResource;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Media\Filament\RelationManagers\MediaRelationManager;
use InOtherShops\Payment\Filament\RelationManagers\PaymentsRelationManager;
use InOtherShops\Pricing\Filament\RelationManagers\PricesRelationManager;
use InOtherShops\Pricing\Filament\Resources\VoucherResource;
use InOtherShops\Purchasing\Filament\Resources\PurchaseOrderResource;
use InOtherShops\Purchasing\Filament\Resources\SupplierResource;
use InOtherShops\Shipping\Filament\RelationManagers\ShipmentsRelationManager;
use InOtherShops\Support\Filament\NavigationGroup;
use InOtherShops\Tax\Filament\Resources\TaxRateResource;
use InOtherShops\Taxonomy\Filament\RelationManagers\CategoriesRelationManager;
use InOtherShops\Taxonomy\Filament\RelationManagers\TagsRelationManager;
use InOtherShops\Taxonomy\Filament\Resources\CategoryResource;
use InOtherShops\Taxonomy\Filament\Resources\TagResource;
use InOtherShops\Variants\Filament\Resources\OptionResource;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The admin sidebar (nav groups, resource nav entries, model labels) and
 * relation-manager titles are localizable — before this they were derived from
 * class names by Filament and were always English, which left a Spanish panel
 * reading "Tax Rates" and "Crear tax rate".
 *
 * The `en` expectations below are the EXACT strings Filament derived before
 * localization. They are a regression guard: an English panel (in-other-worlds)
 * must render byte-identically to the pre-i18n admin.
 */
final class AdminNavigationLabelsTest extends TestCase
{
    /** @return array<string, array{class-string, string, string, string}> */
    public static function resources(): array
    {
        return [
            // resource => [class, nav, modelLabel, pluralModelLabel]
            'TaxRate' => [TaxRateResource::class, 'Tax Rates', 'tax rate', 'tax rates'],
            'Order' => [OrderResource::class, 'Orders', 'order', 'orders'],
            'Customer' => [CustomerResource::class, 'Customers', 'customer', 'customers'],
            'CustomerGroup' => [CustomerGroupResource::class, 'Customer Groups', 'customer group', 'customer groups'],
            'Voucher' => [VoucherResource::class, 'Vouchers', 'voucher', 'vouchers'],
            'Category' => [CategoryResource::class, 'Categories', 'category', 'categories'],
            'Tag' => [TagResource::class, 'Tags', 'tag', 'tags'],
            'Option' => [OptionResource::class, 'Options', 'option', 'options'],
            'PurchaseOrder' => [PurchaseOrderResource::class, 'Purchase Orders', 'purchase order', 'purchase orders'],
            'Supplier' => [SupplierResource::class, 'Suppliers', 'supplier', 'suppliers'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('resources')]
    public function en_resource_labels_are_unchanged_from_filaments_derivation(
        string $resource,
        string $nav,
        string $model,
        string $plural,
    ): void {
        app()->setLocale('en');

        $this->assertSame($nav, $resource::getNavigationLabel());
        $this->assertSame($model, $resource::getModelLabel());
        $this->assertSame($plural, $resource::getPluralModelLabel());
    }

    #[Test]
    public function en_navigation_groups_are_unchanged_from_the_case_names(): void
    {
        app()->setLocale('en');

        foreach (NavigationGroup::cases() as $group) {
            $this->assertSame($group->name, $group->getLabel());
        }
    }

    #[Test]
    public function en_relation_manager_titles_are_unchanged(): void
    {
        app()->setLocale('en');
        $order = new Order;

        $this->assertSame('Orders', CustomerOrdersRelationManager::getTitle($order, 'x'));
        $this->assertSame('Addresses', OrderAddressesRelationManager::getTitle($order, 'x'));
        $this->assertSame('Lines', OrderLinesRelationManager::getTitle($order, 'x'));
        $this->assertSame('Media', MediaRelationManager::getTitle($order, 'x'));
        $this->assertSame('Payments', PaymentsRelationManager::getTitle($order, 'x'));
        $this->assertSame('Prices', PricesRelationManager::getTitle($order, 'x'));
        $this->assertSame('Shipments', ShipmentsRelationManager::getTitle($order, 'x'));
        $this->assertSame('Categories', CategoriesRelationManager::getTitle($order, 'x'));
        $this->assertSame('Tags', TagsRelationManager::getTitle($order, 'x'));
    }

    #[Test]
    public function es_renders_the_sidebar_in_spanish(): void
    {
        app()->setLocale('es');

        $this->assertSame('Tasas de impuesto', TaxRateResource::getNavigationLabel());
        $this->assertSame('Pedidos', OrderResource::getNavigationLabel());
        $this->assertSame('Cupones', VoucherResource::getNavigationLabel());
        $this->assertSame('Órdenes de compra', PurchaseOrderResource::getNavigationLabel());

        $this->assertSame('Comercio', NavigationGroup::Commerce->getLabel());
        $this->assertSame('Impuestos', NavigationGroup::Tax->getLabel());
        $this->assertSame('Compras', NavigationGroup::Purchasing->getLabel());
    }

    #[Test]
    public function es_model_labels_are_not_title_cased_into_nonsense(): void
    {
        app()->setLocale('es');

        // Filament ucwords()es model labels for headings — correct for English
        // ("Tax Rate"), wrong for Spanish ("Tasa De Impuesto"). PackageResource
        // disables title-casing outside en, so the translation's own casing wins.
        $this->assertSame('tasa de impuesto', TaxRateResource::getModelLabel());
        $this->assertSame('tasa de impuesto', TaxRateResource::getTitleCaseModelLabel());
        $this->assertStringNotContainsString(' De ', TaxRateResource::getTitleCaseModelLabel());
    }
}
