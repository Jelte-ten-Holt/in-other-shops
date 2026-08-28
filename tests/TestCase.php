<?php

declare(strict_types=1);

namespace InOtherShops\Tests;

use InOtherShops\Agent\AgentServiceProvider;
use InOtherShops\Commerce\CommerceServiceProvider;
use InOtherShops\Currency\CurrencyServiceProvider;
use InOtherShops\FlowChain\FlowChainServiceProvider;
use InOtherShops\Inventory\InventoryServiceProvider;
use InOtherShops\Location\LocationServiceProvider;
use InOtherShops\Logging\LoggingServiceProvider;
use InOtherShops\Media\MediaServiceProvider;
use InOtherShops\Payment\PaymentServiceProvider;
use InOtherShops\Pricing\PricingServiceProvider;
use InOtherShops\Purchasing\PurchasingServiceProvider;
use InOtherShops\Shipping\ShippingServiceProvider;
use InOtherShops\Storefront\StorefrontServiceProvider;
use InOtherShops\Support\SupportServiceProvider;
use InOtherShops\Tax\TaxServiceProvider;
use InOtherShops\Taxonomy\TaxonomyServiceProvider;
use InOtherShops\Tests\Stubs\StubModel;
use InOtherShops\Tracking\TrackingServiceProvider;
use InOtherShops\Translation\TranslationServiceProvider;
use InOtherShops\Variants\VariantsServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use OPGG\LaravelMcpServer\LaravelMcpServerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        Relation::morphMap(StubModel::stubClasses());
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Stubs/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            SupportServiceProvider::class,
            CurrencyServiceProvider::class,
            TranslationServiceProvider::class,
            LoggingServiceProvider::class,
            LocationServiceProvider::class,
            MediaServiceProvider::class,
            TaxonomyServiceProvider::class,
            PricingServiceProvider::class,
            InventoryServiceProvider::class,
            PurchasingServiceProvider::class,
            TaxServiceProvider::class,
            ShippingServiceProvider::class,
            PaymentServiceProvider::class,
            CommerceServiceProvider::class,
            VariantsServiceProvider::class,
            FlowChainServiceProvider::class,
            StorefrontServiceProvider::class,
            TrackingServiceProvider::class,
            LaravelMcpServerServiceProvider::class,
            AgentServiceProvider::class,
        ];
    }
}
